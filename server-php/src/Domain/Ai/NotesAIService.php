<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Actions\NoteActionService;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Search\NotesSearchService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\RateLimiter;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Pulse: everything the assistant is allowed to do, and the order it must do
 * it in.
 *
 * The order is the feature. Every method here runs the same four steps before
 * a single character of a note goes anywhere:
 *
 *   1. **Is this deployment allowed to?** {@see Features::AI} is off by default
 *      and stays off without a `PULSE_API_URL`, so an unconfigured server
 *      answers 503 rather than half-working.
 *   2. **Is this user allowed to?** {@see NotePermissionService} answers, and
 *      retrieval goes through {@see NotesSearchService::retrieveForAi()}, which
 *      composes the access CTE inside the query. Filtering happens *before*
 *      ranking, in SQL — never after, and never in the client.
 *   3. **Is this note eligible at all?** A trashed note is unreachable and a
 *      `private` note is refused outright. Private means the server holds
 *      ciphertext it cannot read; shipping it to a model would be both useless
 *      and a broken promise, so it is stopped here as well as excluded from
 *      retrieval.
 *   4. **Keep the instructions and the content apart.** The prompt is a
 *      {@see PromptBundle}: our instructions in one field, the user's note text
 *      in another, fenced and labelled as data.
 *
 * And one rule about the answer: anything answered *from* the user's notes
 * comes back with citations, or comes back flagged `grounded: false`. An
 * unsourced sentence presented as though it came from someone's own notes is
 * the specific failure this class exists to prevent.
 */
final class NotesAIService
{
    /** How much of a whole note an action may read. Beyond this it is a retrieval problem, not a prompt. */
    private const MAX_NOTE_CHARS = 18000;
    private const MAX_SELECTION_CHARS = 12000;
    private const MAX_QUESTION_CHARS = 500;

    /** Retrieval blocks per question. Enough to answer from several notes, few enough to stay cheap. */
    private const CONTEXT_BLOCKS = 8;

    /** Actions extracted in one pass. A model that returns two hundred has misread the note. */
    private const MAX_EXTRACTED_ITEMS = 50;

    public function __construct(
        private readonly PulseProvider $provider = new HttpPulseProvider(),
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly NotesSearchService $search = new NotesSearchService(),
        private readonly NoteActionService $actions = new NoteActionService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------------

    /**
     * What Pulse offers here, and whether it is switched on.
     *
     * The one Pulse read that works with the feature off, and deliberately: a
     * menu that renders its items disabled with a reason is honest, whereas a
     * menu that is empty — or worse, full and failing on click — is not. The
     * client renders straight from this.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $enabled = Features::enabled(Features::AI);

        return array_map(static fn (array $action): array => [
            'id' => $action['id'],
            'label' => $action['label'],
            'group' => $action['group'],
            'scope' => $action['scope'],
            'output' => $action['output'],
            'enabled' => $enabled,
        ], AiActionRegistry::all());
    }

    // -----------------------------------------------------------------------
    // Selection
    // -----------------------------------------------------------------------

    /**
     * One structured endpoint for every selection action.
     *
     * The text arrives from the editor rather than from the database, because
     * a selection is a range the server does not have — but it is still note
     * content, so it goes in the context field like any other note text, never
     * into the instructions.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function selection(Identity $identity, array $input): array
    {
        Features::require(Features::AI);
        $action = AiActionRegistry::requireAction($input['action'] ?? null, [AiActionRegistry::SELECTION]);
        $this->meter($action, $identity);

        $text = trim((string) (is_scalar($input['text'] ?? null) ? $input['text'] : ''));
        if ($text === '') {
            throw ApiException::validation(['text' => 'Select some text for Pulse to work on.']);
        }
        $text = Str::limit($text, self::MAX_SELECTION_CHARS);

        // The note is optional — a selection can come from the composer before
        // the note exists — but when it is named, the caller must be able to
        // read it, and the answer cites it.
        $citations = [];
        $noteId = $input['note_id'] ?? null;
        if (is_string($noteId) && $noteId !== '') {
            if (!Uuid::isValid($noteId)) {
                throw ApiException::validation(['note_id' => 'That is not a note id.']);
            }
            $note = $this->noteForAi($identity, strtolower($noteId));
            // Quoted from the note this server read, not from the selection the
            // client sent: a citation must never quote text we did not see.
            $citations = [Citation::forNote(
                (string) $note['id'],
                $note['title'] === null ? null : (string) $note['title'],
                self::noteText($note),
            )];
        }

        return $this->complete(
            $identity,
            $action,
            $this->taskFor($action, $input),
            [['note_id' => $citations === [] ? null : $citations[0]->noteId, 'text' => $text]],
            $citations,
        );
    }

    // -----------------------------------------------------------------------
    // One note
    // -----------------------------------------------------------------------

    /**
     * Every note-scoped action, through one path.
     *
     * `summarize`, `extract-actions` and `meeting-summary` are named routes
     * because they are what a UI actually presses, but they are this method
     * with an action id — the same shortcut-into-one-path arrangement the note
     * flag endpoints use, and for the same reason: the rules cannot drift
     * between the shortcut and the general form.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function onNote(Identity $identity, string $noteId, string $actionId, array $input = []): array
    {
        Features::require(Features::AI);
        $action = AiActionRegistry::requireAction($actionId, [AiActionRegistry::SELECTION, AiActionRegistry::NOTE]);
        $this->meter($action, $identity);

        $note = $this->noteForAi($identity, $noteId);

        if ($action['id'] === 'find_related') {
            return $this->findRelated($identity, $note, $action);
        }

        if ($action['grounding'] === AiActionRegistry::QUESTION) {
            // A question about one note is still a retrieval problem: a long
            // note answers better from its most relevant passages than from its
            // first eighteen thousand characters.
            $question = self::question($input);
            $blocks = $this->search->retrieveForAi($identity, $question, self::CONTEXT_BLOCKS, ['note_id' => $noteId]);

            return $this->complete($identity, $action, $question, ...self::fromRetrieval($blocks));
        }

        $body = self::noteText($note);
        if ($body === '') {
            throw ApiException::validation(['note_id' => 'There is nothing in this note for Pulse to work on.']);
        }

        $citation = Citation::forNote(
            (string) $note['id'],
            $note['title'] === null ? null : (string) $note['title'],
            $body,
        );

        $answer = $this->complete(
            $identity,
            $action,
            $this->taskFor($action, $input),
            [[
                'note_id' => (string) $note['id'],
                'title' => $note['title'] === null ? null : (string) $note['title'],
                'text' => $body,
            ]],
            [$citation],
        );

        return $this->persist($identity, $noteId, $action, $answer, $input);
    }

    // -----------------------------------------------------------------------
    // Ask across notes
    // -----------------------------------------------------------------------

    /**
     * Ask a notebook.
     *
     * The notebook is checked before anything is retrieved, and the retrieval
     * itself is scoped to that notebook and its descendants — sharing
     * "Projects" is expected to cover "Projects / Q3", and a question about it
     * must see the same set a list of it would.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function askNotebook(Identity $identity, string $notebookId, array $input): array
    {
        Features::require(Features::AI);
        $action = AiActionRegistry::requireAction('ask_notebook', [AiActionRegistry::NOTEBOOK]);
        $this->meter($action, $identity);

        $question = self::question($input);

        $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::VIEW);
        $blocks = $this->search->retrieveForAi($identity, $question, self::CONTEXT_BLOCKS, [
            'notebook_id' => $notebookId,
        ]);

        return $this->complete($identity, $action, $question, ...self::fromRetrieval($blocks));
    }

    /**
     * Ask everything the caller can read.
     *
     * The corpus is whatever `retrieveForAi` returns and nothing else: it
     * filters by the access CTE, excludes the trash, excludes archived notes
     * and excludes `private` ones, all in the query. This method never widens
     * that set — there is no path here that reads a note directly.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function askNotes(Identity $identity, array $input): array
    {
        Features::require(Features::AI);
        $action = AiActionRegistry::requireAction('ask_notes', [AiActionRegistry::NOTES]);
        $this->meter($action, $identity);

        $question = self::question($input);

        $scope = [];
        $notebookId = $input['notebook_id'] ?? null;
        if (is_string($notebookId) && $notebookId !== '') {
            if (!Uuid::isValid($notebookId)) {
                throw ApiException::validation(['notebook_id' => 'That is not a notebook id.']);
            }
            $scope['notebook_id'] = strtolower($notebookId);
        }

        $blocks = $this->search->retrieveForAi($identity, $question, self::CONTEXT_BLOCKS, $scope);

        return $this->complete($identity, $action, $question, ...self::fromRetrieval($blocks));
    }

    // -----------------------------------------------------------------------
    // The one path to a provider
    // -----------------------------------------------------------------------

    /**
     * Build the bundle, call the provider, and decide what the answer is
     * allowed to claim.
     *
     * Every public method above ends here, which is what makes "instructions
     * and note text are separate", "citations or `grounded: false`" and "one
     * tenant per request" properties of the class rather than of nineteen
     * call sites. The bundle carries the caller's tenant, and its context is
     * built from a single retrieval for a single identity — there is no path
     * that merges two.
     *
     * @param array<string, string> $action
     * @param array<int, array<string, mixed>> $items
     * @param array<int, Citation> $citations
     * @return array<string, mixed>
     */
    private function complete(
        Identity $identity,
        array $action,
        string $task,
        array $items,
        array $citations,
    ): array {
        $isQuestion = $action['grounding'] === AiActionRegistry::QUESTION;

        $bundle = PromptBundle::create(
            $action['id'],
            $action['output'],
            $action['instruction'],
            $task,
            $identity->tenantId,
            $isQuestion,
        )->withContext($items);

        if ($isQuestion && !$bundle->hasContext()) {
            // Nothing the caller may read matched. Asking anyway would produce a
            // confident answer about notes the model never saw — which is the
            // one thing an assistant inside someone's notes must not do — so
            // the provider is not called at all.
            return self::answer(
                $action,
                'Nothing in your notes matches that question.',
                null,
                [],
                grounded: false,
                model: null,
            );
        }

        $result = $this->provider->complete($bundle);

        [$cited, $grounded] = self::ground($isQuestion, $citations, $result);

        return self::answer($action, $result->text, self::shape($action['output'], $result), $cited, $grounded, $result->model);
    }

    /**
     * Which citations the answer actually earns.
     *
     * For a transform the source is not in doubt — the caller handed it over —
     * so what was sent is what is cited. For a question it is: the model was
     * given eight blocks and may have used two, or none. Citations it did not
     * claim are dropped rather than attached, because a citation list is read
     * as "this came from here"; and when nothing is left, the answer is
     * returned with `grounded: false` rather than suppressed. The user still
     * gets it, labelled as what it is.
     *
     * @param array<int, Citation> $citations
     * @return array{0: array<int, Citation>, 1: bool}
     */
    private static function ground(bool $isQuestion, array $citations, AiResult $result): array
    {
        if ($citations === []) {
            return [[], false];
        }

        if (!$isQuestion) {
            return [$citations, true];
        }

        $indexes = $result->citedContext !== [] ? $result->citedContext : Citation::referencedIndexes($result->text);
        $cited = Citation::select($citations, $indexes);

        return $cited === [] ? [[], false] : [$cited, true];
    }

    /**
     * @param array<string, string> $action
     * @param array<int, Citation> $citations
     * @return array<string, mixed>
     */
    private static function answer(
        array $action,
        string $text,
        mixed $data,
        array $citations,
        bool $grounded,
        ?string $model,
    ): array {
        return [
            'action' => $action['id'],
            'output' => $action['output'],
            'answer' => $text,
            'data' => $data,
            'citations' => Citation::toArrayList($citations),
            // The single most useful thing to know about an AI answer: was it
            // read out of your notes, or made up around them?
            'grounded' => $grounded,
            'model' => $model,
        ];
    }

    // -----------------------------------------------------------------------
    // Related notes
    // -----------------------------------------------------------------------

    /**
     * "What else have I written about this?"
     *
     * The note the user is reading becomes the query, and the candidates come
     * back permission-filtered like any other retrieval. The note itself is
     * dropped from its own results — it is block 1 either way.
     *
     * @param array<string, mixed> $note
     * @param array<string, string> $action
     * @return array<string, mixed>
     */
    private function findRelated(Identity $identity, array $note, array $action): array
    {
        $body = self::noteText($note);
        $query = self::relatedQuery($note['title'] === null ? '' : (string) $note['title'], $body);
        if ($query === '') {
            throw ApiException::validation(['note_id' => 'There is nothing in this note to match against.']);
        }

        $noteId = (string) $note['id'];
        $blocks = [];
        foreach ($this->search->retrieveForAi($identity, $query, self::CONTEXT_BLOCKS) as $block) {
            if ((string) ($block['note_id'] ?? '') !== $noteId) {
                $blocks[] = $block;
            }
        }

        [$items, $citations] = self::fromRetrieval($blocks);

        array_unshift(
            $items,
            [
                'note_id' => $noteId,
                'title' => $note['title'] === null ? null : (string) $note['title'],
                'text' => Str::limit($body, 4000),
            ],
        );
        array_unshift(
            $citations,
            Citation::forNote($noteId, $note['title'] === null ? null : (string) $note['title'], $body),
        );

        return $this->complete(
            $identity,
            $action,
            'Which of the other blocks relate to the note in block 1, and why?',
            $items,
            $citations,
        );
    }

    /**
     * A note, as a query for finding its neighbours.
     *
     * Handing the whole note to search would find nothing: retrieval runs
     * `websearch_to_tsquery`, which **ands** its terms, and no second note
     * contains every word of the first. So the note is reduced to the handful
     * of words it uses most — its subject, in practice — joined with `or`,
     * which is the operator that syntax spells as a word.
     *
     * Words of three characters or fewer are dropped: they are almost entirely
     * stop words, and a query of "the or and or for" would rank every note the
     * user owns as equally related.
     */
    private static function relatedQuery(string $title, string $body): string
    {
        $tokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            mb_strtolower($title . ' ' . Str::limit($body, 4000), 'UTF-8'),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $counts = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') > 3) {
                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }

        arsort($counts);

        return implode(' or ', array_slice(array_keys($counts), 0, 8));
    }

    // -----------------------------------------------------------------------
    // Writing back
    // -----------------------------------------------------------------------

    /**
     * Save what Pulse produced — only when asked, and only with edit rights.
     *
     * Nothing Pulse returns is written to a note by default. Extracted actions
     * and a meeting summary are suggestions until the caller says otherwise,
     * because a model that misreads "we should probably cancel the audit" as a
     * commitment must not be able to put it on somebody's task list on its own.
     *
     * @param array<string, string> $action
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function persist(Identity $identity, string $noteId, array $action, array $answer, array $input): array
    {
        $save = ($input['save'] ?? false) === true;
        $answer['saved'] = false;
        if (!$save) {
            return $answer;
        }

        // Writing to the note is an edit, whatever produced the text.
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::EDIT, columns: 'n.id');

        if ($action['id'] === 'extract_actions' || $action['id'] === 'to_checklist') {
            $saved = [];
            foreach (array_slice((array) ($answer['data']['items'] ?? []), 0, self::MAX_EXTRACTED_ITEMS) as $item) {
                $saved[] = $this->actions->create($identity, $noteId, [
                    'text' => $item['text'] ?? '',
                    'due_at' => $item['due_at'] ?? null,
                    'priority' => $item['priority'] ?? null,
                ], 'pulse');
            }
            $answer['data']['saved_actions'] = $saved;
            $answer['saved'] = $saved !== [];

            return $answer;
        }

        if ($action['id'] === 'meeting_minutes') {
            $structured = is_array($answer['data']) ? $answer['data'] : [];
            $summary = is_string($structured['summary'] ?? null) ? (string) $structured['summary'] : $answer['answer'];
            $decisions = is_array($structured['decisions'] ?? null) ? $structured['decisions'] : [];

            Connection::execute(
                'INSERT INTO note_meetings (note_id, summary, summary_model, summary_at, decisions)
                 VALUES (:note_id, :summary, :model, now(), :decisions::jsonb)
                 ON CONFLICT (note_id) DO UPDATE SET
                    summary = EXCLUDED.summary,
                    summary_model = EXCLUDED.summary_model,
                    summary_at = EXCLUDED.summary_at,
                    decisions = EXCLUDED.decisions,
                    updated_at = now()',
                [
                    'note_id' => $noteId,
                    'summary' => Str::limit($summary, 20000),
                    'model' => $answer['model'] === null ? null : Str::limit((string) $answer['model'], 120),
                    'decisions' => (string) json_encode(array_slice($decisions, 0, self::MAX_EXTRACTED_ITEMS)),
                ],
            );
            $answer['saved'] = true;
        }

        return $answer;
    }

    // -----------------------------------------------------------------------
    // Guards and helpers
    // -----------------------------------------------------------------------

    /**
     * Charge the request to the bucket its work belongs to.
     *
     * Always after {@see Features::require()}, so a deployment that never
     * offered Pulse cannot spend a user's budget on the 503 it was always
     * going to answer. And keyed on the action rather than the route, because
     * the cost is in the work: `find_related` arrives through a note endpoint
     * but retrieves across the whole corpus, so it is charged like the other
     * corpus reads rather than like a toolbar click. Typing must never be what
     * exhausts a limit, so selection actions sit in the generous bucket.
     *
     * @param array<string, string> $action
     */
    private function meter(array $action, Identity $identity): void
    {
        RateLimiter::hit(
            ($action['reads'] ?? '') === AiActionRegistry::CORPUS ? 'ai_heavy' : 'ai',
            $identity->userId,
        );
    }

    /**
     * A note this caller may read *and* this server may send onwards.
     *
     * Two separate questions. `requireNote` answers the first, and answers 404
     * for a note that is trashed or invisible so ids cannot be probed. The
     * second is the privacy mode: the owner can open a private note, but its
     * document is ciphertext this server cannot read, and a deployment must
     * never quietly hand it to an AI provider. Retrieval already excludes
     * private notes in SQL; this closes the direct path.
     *
     * @return array<string, mixed>
     */
    private function noteForAi(Identity $identity, string $noteId): array
    {
        $note = $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW);

        if ((string) ($note['privacy_mode'] ?? 'standard') === 'private') {
            throw ApiException::forbidden(
                'NOTE_PRIVATE',
                'This note is encrypted on your device, so it is never sent to Pulse.',
            );
        }

        return $note;
    }

    /**
     * The readable text of a note: what its author wrote, plus what the
     * pipeline read off its attachments and recordings.
     *
     * They are labelled but kept in one block, so the answer cites one note
     * rather than appearing to have two sources for the same page.
     *
     * @param array<string, mixed> $note
     */
    private static function noteText(array $note): string
    {
        $body = trim((string) ($note['extracted_text'] ?? ''));
        $derived = trim((string) ($note['derived_text'] ?? ''));

        if ($derived !== '') {
            $body = trim($body . "\n\n(text read from attachments and recordings on this note)\n" . $derived);
        }

        return Str::limit($body, self::MAX_NOTE_CHARS);
    }

    /**
     * Retrieval chunks → prompt blocks and citations, index for index.
     *
     * The alignment is load-bearing: a model that answers "[2]" is naming the
     * second block it was given, and {@see Citation::select()} turns that back
     * into the note it came from. Reordering one list without the other would
     * make every citation point at the wrong note.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, Citation>}
     */
    private static function fromRetrieval(array $rows): array
    {
        $items = [];
        $citations = [];

        foreach ($rows as $row) {
            $items[] = [
                'note_id' => (string) ($row['note_id'] ?? ''),
                'title' => isset($row['title']) && $row['title'] !== null ? (string) $row['title'] : null,
                'block_id' => isset($row['block_id']) && $row['block_id'] !== null ? (string) $row['block_id'] : null,
                'text' => (string) ($row['snippet'] ?? ''),
            ];
            $citations[] = Citation::fromRetrieval($row);
        }

        return [$items, $citations];
    }

    /** @param array<string, mixed> $input */
    private static function question(array $input): string
    {
        $question = trim((string) (is_scalar($input['question'] ?? null) ? $input['question'] : ''));
        if ($question === '') {
            throw ApiException::validation(['question' => 'Ask Pulse something.']);
        }

        return Str::limit($question, self::MAX_QUESTION_CHARS);
    }

    /**
     * What the user is asking for, as a user turn.
     *
     * Options are named in this message and never in the instructions: the
     * target language of a translation is the user's request, and a request
     * belongs on the user's side of the prompt.
     *
     * @param array<string, string> $action
     * @param array<string, mixed> $input
     */
    private function taskFor(array $action, array $input): string
    {
        $task = sprintf('%s. Work on the text in the CONTEXT.', $action['label']);

        if ($action['id'] === 'translate') {
            $language = trim((string) (is_scalar($input['language'] ?? null) ? $input['language'] : ''));
            // A language name, not free text: this string names an option, and
            // an option is not a place to smuggle a paragraph of instructions.
            $language = (string) preg_replace('/[^\p{L}\p{N}\s\-()]/u', '', $language);
            if (trim($language) === '') {
                throw ApiException::validation(['language' => 'Name the language to translate into.']);
            }
            $task .= ' Target language: ' . Str::limit(trim($language), 40) . '.';
        }

        return $task;
    }

    // -----------------------------------------------------------------------
    // Output shapes
    // -----------------------------------------------------------------------

    /**
     * Turn the answer into the shape the action promised.
     *
     * A model asked for JSON usually returns JSON, sometimes returns JSON in a
     * code fence, and occasionally returns a bulleted list. All three are
     * handled, and none of them silently become an empty result: the prose is
     * always returned alongside in `answer`, so a client can show what came
     * back even when the structure did not parse.
     */
    private static function shape(string $output, AiResult $result): mixed
    {
        $decoded = $result->data !== [] ? $result->data : self::decodeJson($result->text);

        return match ($output) {
            'checklist' => ['items' => self::checklistItems($decoded, $result->text)],
            'table' => self::table($decoded),
            'structured' => $decoded,
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @return array<int, array<string, mixed>>
     */
    private static function checklistItems(?array $decoded, string $text): array
    {
        $raw = [];
        if (is_array($decoded['items'] ?? null)) {
            $raw = $decoded['items'];
        } elseif ($decoded !== null && array_is_list($decoded)) {
            $raw = $decoded;
        } else {
            // The bulleted-list fallback. A model that answered in prose still
            // gave a usable list; refusing it would throw away a good answer
            // over its formatting.
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $item = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line));
                if ($item !== '' && $item !== $line) {
                    $raw[] = $item;
                }
            }
        }

        $items = [];
        foreach (array_slice($raw, 0, self::MAX_EXTRACTED_ITEMS) as $entry) {
            $item = is_array($entry) ? $entry : ['text' => $entry];
            $itemText = trim((string) (is_scalar($item['text'] ?? null) ? $item['text'] : ''));
            if ($itemText === '') {
                continue;
            }

            $items[] = [
                'text' => Str::limit($itemText, 2000),
                'due_at' => self::optionalString($item['due_at'] ?? $item['due'] ?? null, 40),
                'assignee' => self::optionalString($item['assignee'] ?? null, 120),
                'priority' => self::optionalString($item['priority'] ?? null, 20),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @return array{columns: array<int, string>, rows: array<int, array<int, string>>}|null
     */
    private static function table(?array $decoded): ?array
    {
        if (!is_array($decoded['columns'] ?? null) || !is_array($decoded['rows'] ?? null)) {
            return null;
        }

        $columns = [];
        foreach (array_slice($decoded['columns'], 0, 20) as $column) {
            $columns[] = Str::limit(trim((string) (is_scalar($column) ? $column : '')), 120);
        }

        $rows = [];
        foreach (array_slice($decoded['rows'], 0, 200) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach (array_slice(array_values($row), 0, count($columns)) as $cell) {
                $cells[] = Str::limit(trim((string) (is_scalar($cell) ? $cell : '')), 500);
            }
            $rows[] = $cells;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The JSON in an answer, fenced or not.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJson(string $text): ?array
    {
        $trimmed = trim($text);
        // ```json … ``` is the most common wrapper, and stripping it is cheaper
        // than insisting the model never adds it.
        $trimmed = (string) preg_replace('/^```[a-z]*\s*|\s*```$/iu', '', $trimmed);

        $start = strcspn($trimmed, '{[');
        if ($start >= strlen($trimmed)) {
            return null;
        }
        $candidate = substr($trimmed, $start);
        $end = strrpos($candidate, $candidate[0] === '{' ? '}' : ']');
        if ($end === false) {
            return null;
        }

        $decoded = json_decode(substr($candidate, 0, $end + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function optionalString(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : Str::limit($string, $max);
    }
}
