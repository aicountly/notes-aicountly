<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;

/**
 * What Pulse can be asked to do, as data.
 *
 * Sixteen selection actions plus the three questions are one table here and
 * one code path in {@see NotesAIService}, rather than nineteen near-identical
 * methods. The difference matters twice: adding "translate to Hindi" or
 * "extract invoice numbers" is a row rather than an endpoint, and every action
 * necessarily inherits the same permission check, the same rate limit and the
 * same prompt separation — a hand-written seventeenth endpoint is exactly where
 * one of those gets forgotten.
 *
 * Each row declares:
 *
 *   - `scope` — the **smallest** input the action needs. A `selection` action
 *     also runs against a whole note (a note is a longer selection), which is
 *     what lets `POST /pulse/note/{id}/summarize` be the same `summarise` the
 *     bubble toolbar calls. A `note` action cannot run on a selection.
 *   - `output` — the shape of the answer, so the client knows before it asks
 *     whether it is rendering prose, dropping a fragment into the document,
 *     building a checklist or drawing a table.
 *   - `grounding` — `question` for anything answered *from* the user's notes,
 *     which must carry citations; `transform` for work done *on* text the
 *     caller already has in front of them.
 *   - `reads` — `corpus` when the action retrieves across notes rather than
 *     reading only what the caller handed over. That is the expensive kind, so
 *     it decides the rate-limit bucket; absent means the cheap kind.
 *   - `instruction` — the system text for this action. Ours, always; note text
 *     never joins it. See {@see PromptBundle}.
 */
final class AiActionRegistry
{
    public const SELECTION = 'selection';
    public const NOTE = 'note';
    public const NOTEBOOK = 'notebook';
    public const NOTES = 'notes';

    /** Answers drawn from the user's own notes. These must cite. */
    public const QUESTION = 'question';
    /** Work done on text the caller supplied or is already looking at. */
    public const TRANSFORM = 'transform';

    /** Retrieves across the user's notes, so it is charged to the heavy bucket. */
    public const CORPUS = 'corpus';

    /** @var array<string, array<string, string>> */
    private const ACTIONS = [
        // -- Understand ------------------------------------------------------
        'summarise' => [
            'label' => 'Summarise',
            'group' => 'understand',
            'scope' => self::SELECTION,
            'output' => 'text',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Summarise the text in the context. Keep the facts, names, figures and dates '
                . 'exactly as written, drop the padding, and add nothing that is not there.',
        ],
        'explain' => [
            'label' => 'Explain',
            'group' => 'understand',
            'scope' => self::SELECTION,
            'output' => 'text',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Explain the text in the context in plain language, as if to a colleague who '
                . 'has not seen it. Say plainly when the text itself is unclear rather than filling the gap.',
        ],

        // -- Write -----------------------------------------------------------
        'improve_writing' => [
            'label' => 'Improve writing',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Rewrite the text in the context so it reads better: clearer sentences, no '
                . 'repetition, the author\'s own voice and vocabulary kept. Do not change what it claims.',
        ],
        'rewrite' => [
            'label' => 'Rewrite',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Rewrite the text in the context, keeping every fact and figure, and follow any '
                . 'style the user asked for in their message.',
        ],
        'shorten' => [
            'label' => 'Make shorter',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Rewrite the text in the context in noticeably fewer words. Nothing load-bearing '
                . 'may be dropped — cut wording, not content.',
        ],
        'expand' => [
            'label' => 'Expand',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Expand the text in the context into fuller prose, developing only what is '
                . 'already implied by it. Invent no new facts, names, figures or commitments.',
        ],
        'fix_grammar' => [
            'label' => 'Fix spelling & grammar',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Correct spelling, grammar and punctuation in the text in the context. Change '
                . 'nothing else: not the wording, not the tone, not the formatting.',
        ],
        'translate' => [
            'label' => 'Translate',
            'group' => 'write',
            'scope' => self::SELECTION,
            'output' => 'document_fragment',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Translate the text in the context into the language the user names. Keep names, '
                . 'numbers, dates and formatting as they are, and translate nothing that is a proper noun.',
        ],

        // -- Transform -------------------------------------------------------
        'to_checklist' => [
            'label' => 'Turn into a checklist',
            'group' => 'transform',
            'scope' => self::SELECTION,
            'output' => 'checklist',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Turn the text in the context into checklist items, one per thing that can be '
                . 'ticked off. Use the text\'s own wording. Do not invent items to round the list out.',
        ],
        'create_table' => [
            'label' => 'Make a table',
            'group' => 'transform',
            'scope' => self::SELECTION,
            'output' => 'table',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Arrange the text in the context as a table. Choose columns the text actually '
                . 'supports, and leave a cell empty rather than guessing at it.',
        ],

        // -- Extract ---------------------------------------------------------
        'extract_actions' => [
            'label' => 'Find action items',
            'group' => 'extract',
            'scope' => self::SELECTION,
            'output' => 'checklist',
            'grounding' => self::TRANSFORM,
            'instruction' => 'List the actions somebody committed to in the context: things to be done, by '
                . 'whom, by when. Only real commitments — an idea that was discussed and dropped is not one. '
                . 'Give the owner and the date only where the text states them.',
        ],
        'extract_dates' => [
            'label' => 'Find dates & deadlines',
            'group' => 'extract',
            'scope' => self::SELECTION,
            'output' => 'structured',
            'grounding' => self::TRANSFORM,
            'instruction' => 'List every date, deadline and time window in the context as '
                . '{"dates": [{"date": "YYYY-MM-DD"|null, "as_written": "…", "what": "…"}]}. '
                . 'Leave "date" null when the text is relative ("next Friday") and no anchor date is given.',
        ],
        'extract_people' => [
            'label' => 'Find people',
            'group' => 'extract',
            'scope' => self::SELECTION,
            'output' => 'structured',
            'grounding' => self::TRANSFORM,
            'instruction' => 'List the people named in the context as '
                . '{"people": [{"name": "…", "role": "…"|null, "mentioned_for": "…"|null}]}. '
                . 'Only people the text names.',
        ],
        'identify_decisions' => [
            'label' => 'Find decisions',
            'group' => 'extract',
            'scope' => self::NOTE,
            'output' => 'structured',
            'grounding' => self::TRANSFORM,
            'instruction' => 'List the decisions recorded in the context as '
                . '{"decisions": [{"decision": "…", "decided_by": "…"|null, "rationale": "…"|null}]}. '
                . 'A decision is something settled, not something still being weighed up.',
        ],

        // -- Meetings --------------------------------------------------------
        'meeting_minutes' => [
            'label' => 'Meeting minutes',
            'group' => 'meeting',
            'scope' => self::NOTE,
            'output' => 'structured',
            'grounding' => self::TRANSFORM,
            'instruction' => 'Write minutes for the meeting recorded in the context as '
                . '{"summary": "…", "participants": ["…"], "decisions": [{"decision": "…", "decided_by": "…"|null}], '
                . '"actions": [{"text": "…", "assignee": "…"|null, "due_at": "YYYY-MM-DD"|null}], "open_questions": ["…"]}. '
                . 'Use only what was said. An empty list is the right answer when nothing was decided.',
        ],

        // -- Connect ---------------------------------------------------------
        'find_related' => [
            'label' => 'Find related notes',
            'group' => 'connect',
            'scope' => self::NOTE,
            'output' => 'structured',
            'grounding' => self::QUESTION,
            'reads' => self::CORPUS,
            'instruction' => 'The first block is the note the user is reading; the rest are other notes of '
                . 'theirs. Say which of the others genuinely relate to it and why, as '
                . '{"related": [{"block": 1, "why": "…"}]}, citing each block number. Leave the list empty '
                . 'when nothing really relates — a weak connection is worse than none.',
        ],

        // -- Questions -------------------------------------------------------
        'ask_note' => [
            'label' => 'Ask this note',
            'group' => 'ask',
            'scope' => self::NOTE,
            'output' => 'text',
            'grounding' => self::QUESTION,
            'instruction' => 'Answer the user\'s question using only the blocks in the context, which come '
                . 'from the note they are reading.',
        ],
        'ask_notebook' => [
            'label' => 'Ask this notebook',
            'group' => 'ask',
            'scope' => self::NOTEBOOK,
            'output' => 'text',
            'grounding' => self::QUESTION,
            'reads' => self::CORPUS,
            'instruction' => 'Answer the user\'s question using only the blocks in the context, which come '
                . 'from notes in one notebook of theirs.',
        ],
        'ask_notes' => [
            'label' => 'Ask my notes',
            'group' => 'ask',
            'scope' => self::NOTES,
            'output' => 'text',
            'grounding' => self::QUESTION,
            'reads' => self::CORPUS,
            'instruction' => 'Answer the user\'s question using only the blocks in the context, which were '
                . 'retrieved from the notes this user is allowed to read.',
        ],
    ];

    /**
     * The catalogue, in menu order.
     *
     * @return array<int, array<string, string>>
     */
    public static function all(): array
    {
        $actions = [];
        foreach (self::ACTIONS as $id => $definition) {
            $actions[] = ['id' => $id] + $definition;
        }

        return $actions;
    }

    /** @return array<string, string>|null */
    public static function find(string $id): ?array
    {
        $definition = self::ACTIONS[$id] ?? null;

        return $definition === null ? null : ['id' => $id] + $definition;
    }

    /**
     * Resolve an action id, or refuse.
     *
     * The scope check is what stops `POST /pulse/selection` being handed
     * `ask_notes` and quietly turning a cheap, selection-sized request into a
     * retrieval across everything the caller can read — a different cost and a
     * different rate-limit bucket.
     *
     * @param array<int, string> $scopes The input this endpoint can supply.
     * @return array<string, string>
     */
    public static function requireAction(mixed $id, array $scopes): array
    {
        $action = is_string($id) ? self::find($id) : null;
        if ($action === null) {
            throw ApiException::validation([
                'action' => sprintf('`%s` is not a Pulse action.', Str::limit(is_scalar($id) ? (string) $id : '', 40)),
            ]);
        }

        if (!in_array($action['scope'], $scopes, true)) {
            throw ApiException::validation([
                'action' => sprintf('`%s` needs a whole %s to work on.', $action['id'], $action['scope']),
            ]);
        }

        return $action;
    }
}
