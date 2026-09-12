<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Actions\NoteActionService;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Domain\Links\NoteLinkService;
use Aicountly\Api\Domain\Tags\TagService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Everything a note *does*.
 *
 * Controllers translate HTTP into calls on this class and back again; none of
 * the rules below live in a controller, which is what lets the same behaviour
 * back a future browser extension or share-sheet without being rewritten.
 *
 * The two rules worth stating outright:
 *
 *   - **A save never silently loses writing.** Every update carries the version
 *     the client last saw. A stale version is answered with 409 and the server's
 *     current note, not overwritten.
 *   - **A create never waits for anything optional.** Tags, links, checklist
 *     reconciliation and search text all happen inside the same transaction as
 *     the insert, but nothing calls out to Drive, Pulse or the portal on the
 *     path that makes a new note appear.
 */
final class NotesService
{
    public const NOTE_TYPES = ['document', 'checklist', 'voice', 'meeting', 'drawing', 'canvas', 'scan'];
    private const COLORS = [
        'default', 'coral', 'peach', 'sand', 'sage', 'mint',
        'sky', 'lavender', 'blush', 'graphite',
    ];

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly NoteRepository $repository = new NoteRepository(),
        private readonly NoteRevisionService $revisions = new NoteRevisionService(),
        private readonly TagService $tags = new TagService(),
        private readonly NoteLinkService $links = new NoteLinkService(),
        private readonly NoteActionService $actions = new NoteActionService(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
        private readonly JobQueue $jobs = new JobQueue(),
    ) {
    }

    /**
     * Ask for this note to be re-embedded.
     *
     * Queued on every content change, and cheap by design: the handler skips
     * entirely where semantic search is off or no gateway is configured, and
     * re-embeds only the chunks whose text actually changed. Queued *after*
     * the write, never inside its transaction, so a queue problem cannot cost
     * the user their save.
     */
    private function queueEmbedding(Identity $identity, string $noteId): void
    {
        if (!Features::enabled(Features::SEMANTIC_SEARCH)) {
            return;
        }

        $this->jobs->enqueue($identity, JobQueue::EMBEDDING, $noteId);
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    public function list(Identity $identity, NoteQuery $filters, array $options): array
    {
        return $this->repository->list($identity, $filters, $options);
    }

    /** @return array<string, mixed> */
    public function get(Identity $identity, string $noteId, bool $includeTrashed = false): array
    {
        $row = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            includeTrashed: $includeTrashed,
        );

        return NotePresenter::detail($row, [
            'tags' => $this->tagsFor($noteId),
            'attachment_count' => $this->scalar(
                'SELECT count(*) FROM note_attachments WHERE note_id = :id AND deleted_at IS NULL',
                $noteId,
            ),
            'has_reminder' => $this->scalar(
                "SELECT count(*) FROM note_reminders WHERE note_id = :id AND deleted_at IS NULL
                 AND status IN ('scheduled','snoozed')",
                $noteId,
            ) > 0,
            'is_shared' => $this->scalar('SELECT count(*) FROM note_members WHERE note_id = :id', $noteId) > 0,
            'append' => [
                'backlink_count' => $this->links->backlinkCount($identity, $noteId),
                'actions' => $this->actions->listForNote($noteId),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, array $input): array
    {
        // The client may supply the id it already used locally, so a note
        // created offline keeps the same identity when the queue drains.
        $noteId = isset($input['id']) && Uuid::isValid($input['id'])
            ? strtolower((string) $input['id'])
            : Uuid::v4();

        $noteType = $this->noteType($input['note_type'] ?? 'document');
        $privacyMode = $this->privacyMode($input['privacy_mode'] ?? 'standard');

        $document = isset($input['document'])
            ? NoteDocument::sanitize($input['document'])
            : (isset($input['content']) && is_string($input['content'])
                ? NoteDocument::fromPlainText((string) $input['content'])
                : NoteDocument::empty());

        $notebookId = $this->resolveNotebook($identity, $input['notebook_id'] ?? null);
        $title = $this->title($input['title'] ?? null);
        $extractedText = NoteDocument::extractText($document);
        $counts = NoteDocument::counts($document);
        $contentHash = Str::contentHash(($title ?? '') . "\n" . $extractedText);

        $created = Connection::transaction(function () use (
            $identity, $noteId, $noteType, $privacyMode, $document, $notebookId,
            $title, $extractedText, $counts, $contentHash, $input
        ): array {
            $existing = Connection::selectOne('SELECT id FROM notes WHERE id = :id', ['id' => $noteId]);
            if ($existing !== null) {
                // Replaying a queued create must not produce a second note.
                return $this->get($identity, $noteId);
            }

            Connection::execute(
                'INSERT INTO notes
                    (id, tenant_id, owner_user_id, notebook_id, note_type, title, document_json,
                     document_schema_version, extracted_text, color, is_pinned, is_favourite,
                     privacy_mode, version, content_hash, source, template_key, language,
                     word_count, char_count, created_by, updated_by)
                 VALUES
                    (:id, :tenant_id, :owner, :notebook_id, :note_type, :title, :document::jsonb,
                     :schema_version, :extracted_text, :color, :is_pinned, :is_favourite,
                     :privacy_mode, 1, :content_hash, :source, :template_key, :language,
                     :word_count, :char_count, :actor, :actor)',
                [
                    'id' => $noteId,
                    'tenant_id' => $identity->tenantId,
                    'owner' => $identity->userId,
                    'notebook_id' => $notebookId,
                    'note_type' => $noteType,
                    'title' => $title,
                    'document' => json_encode($document, JSON_UNESCAPED_SLASHES),
                    'schema_version' => NoteDocument::SCHEMA_VERSION,
                    'extracted_text' => $extractedText,
                    'color' => $this->color($input['color'] ?? null),
                    'is_pinned' => (bool) ($input['is_pinned'] ?? false),
                    'is_favourite' => (bool) ($input['is_favourite'] ?? false),
                    'privacy_mode' => $privacyMode,
                    'content_hash' => $contentHash,
                    'source' => isset($input['source']) ? Str::limit((string) $input['source'], 40) : 'web',
                    'template_key' => isset($input['template_key']) ? Str::limit((string) $input['template_key'], 60) : null,
                    'language' => isset($input['language']) ? Str::limit((string) $input['language'], 16) : null,
                    'word_count' => $counts['words'],
                    'char_count' => $counts['characters'],
                    'actor' => $identity->userId,
                ],
            );

            if (isset($input['tags']) && is_array($input['tags'])) {
                $this->tags->setForNote($identity, $noteId, $input['tags']);
            }

            $this->syncDerived($identity, $noteId, $document, $privacyMode);
            $this->revisions->capture($noteId, $title, $document, $extractedText, $contentHash, $identity->userId, 'manual');
            $this->activity->record($identity, ActivityRecorder::NOTE_CREATED, $noteId, $notebookId, [
                'note_type' => $noteType,
                'source' => $input['source'] ?? 'web',
            ]);

            return $this->get($identity, $noteId);
        });

        $this->queueEmbedding($identity, $noteId);

        return $created;
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    /**
     * Save changes to a note.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $noteId, array $input): array
    {
        $note = $this->permissions->requireNote($identity, $noteId, NotePermissionService::EDIT);

        $touchesDocument = array_key_exists('document', $input) || array_key_exists('title', $input);

        // Optimistic concurrency. Only content edits are version-checked:
        // pinning a note from a stale list must not fail, but overwriting its
        // text with a stale document must.
        //
        // This read answers early and with the server's copy attached, which is
        // what the editor needs to show a conflict. It is NOT what enforces the
        // rule: the same number goes into the UPDATE's WHERE clause below, so
        // two saves that both read version 4 in the same instant cannot both
        // pass. See {@see self::expectedVersion()}.
        $expectedVersion = null;
        if ($touchesDocument && array_key_exists('version', $input)) {
            $clientVersion = (int) $input['version'];
            $serverVersion = (int) $note['version'];
            $expectedVersion = $clientVersion;
            if ($clientVersion !== $serverVersion) {
                throw ApiException::conflict(
                    'This note was changed somewhere else while you were editing.',
                    [
                        'server_version' => $serverVersion,
                        'client_version' => $clientVersion,
                        'note' => NotePresenter::detail($note),
                    ],
                );
            }
        }

        $updates = [];
        $bindings = ['id' => $noteId, 'actor' => $identity->userId];
        $document = null;
        $title = $note['title'] === null ? null : (string) $note['title'];

        if (array_key_exists('title', $input)) {
            $title = $this->title($input['title']);
            $updates[] = 'title = :title';
            $bindings['title'] = $title;
        }

        if (array_key_exists('document', $input)) {
            $document = NoteDocument::sanitize($input['document']);
            $extractedText = NoteDocument::extractText($document);
            $counts = NoteDocument::counts($document);

            $updates[] = 'document_json = :document::jsonb';
            $updates[] = 'extracted_text = :extracted_text';
            $updates[] = 'word_count = :word_count';
            $updates[] = 'char_count = :char_count';
            $updates[] = 'document_schema_version = :schema_version';
            $bindings['document'] = json_encode($document, JSON_UNESCAPED_SLASHES);
            $bindings['extracted_text'] = $extractedText;
            $bindings['word_count'] = $counts['words'];
            $bindings['char_count'] = $counts['characters'];
            $bindings['schema_version'] = NoteDocument::SCHEMA_VERSION;
        }

        if ($touchesDocument) {
            $extractedText ??= (string) ($note['extracted_text'] ?? '');
            $contentHash = Str::contentHash(($title ?? '') . "\n" . $extractedText);
            $updates[] = 'content_hash = :content_hash';
            $bindings['content_hash'] = $contentHash;
            // Only a content change advances the version, so the number keeps
            // meaning "this is the document you were editing".
            $updates[] = 'version = version + 1';
        }

        foreach (['is_pinned', 'is_favourite', 'is_archived'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $updates[] = $flag . ' = :' . $flag;
                $bindings[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('color', $input)) {
            $updates[] = 'color = :color';
            $bindings['color'] = $this->color($input['color']);
        }

        if (array_key_exists('notebook_id', $input)) {
            $updates[] = 'notebook_id = :notebook_id';
            $bindings['notebook_id'] = $this->resolveNotebook($identity, $input['notebook_id']);
        }

        if (array_key_exists('note_type', $input)) {
            $updates[] = 'note_type = :note_type';
            $bindings['note_type'] = $this->noteType($input['note_type']);
        }

        if (array_key_exists('is_locked', $input)) {
            $updates[] = 'is_locked = :is_locked';
            $bindings['is_locked'] = (bool) $input['is_locked'];
        }

        if ($updates === [] && !array_key_exists('tags', $input)) {
            return $this->get($identity, $noteId);
        }

        $updated = Connection::transaction(function () use (
            $identity, $noteId, $note, $updates, $bindings, $input, $document, $title, $expectedVersion
        ): array {
            if ($updates !== []) {
                $updates[] = 'updated_by = :actor';
                $updates[] = 'updated_at = now()';

                // The version predicate is the check that actually holds. The
                // read above happens before this transaction opens, so two
                // saves racing on one note both see version 4 and both decide
                // they are safe; without `AND version = 4` here they then both
                // write, both increment, and the second silently replaces a
                // document the first had just stored. With it, the loser
                // matches no row.
                $sql = 'UPDATE notes SET ' . implode(', ', $updates) . ' WHERE id = :id AND deleted_at IS NULL';
                if ($expectedVersion !== null) {
                    $sql .= ' AND version = :expected_version';
                    $bindings['expected_version'] = $expectedVersion;
                }

                $changed = Connection::execute($sql, $bindings);

                if ($changed === 0 && $expectedVersion !== null) {
                    // Re-read rather than reporting the number this request
                    // arrived with: the winner has already incremented it, and
                    // the editor needs their copy to show what it lost to.
                    $current = $this->get($identity, $noteId);

                    throw ApiException::conflict(
                        'This note was changed somewhere else while you were editing.',
                        [
                            'server_version' => (int) $current['version'],
                            'client_version' => $expectedVersion,
                            'note' => $current,
                        ],
                    );
                }
            }

            if (array_key_exists('tags', $input) && is_array($input['tags'])) {
                $this->tags->setForNote($identity, $noteId, $input['tags']);
            }

            if ($document !== null) {
                $privacyMode = (string) ($note['privacy_mode'] ?? 'standard');
                $this->syncDerived($identity, $noteId, $document, $privacyMode);
                $this->revisions->capture(
                    $noteId,
                    $title,
                    $document,
                    (string) $bindings['extracted_text'],
                    (string) $bindings['content_hash'],
                    $identity->userId,
                    (string) ($input['revision_reason'] ?? 'autosave'),
                );
            }

            $this->recordStateActivity($identity, $noteId, $note, $input);

            return $this->get($identity, $noteId);
        });

        // Only a content change moves the text a semantic index is built from;
        // pinning a note does not.
        if ($touchesDocument) {
            $this->queueEmbedding($identity, $noteId);
        }

        return $updated;
    }

    /**
     * Derived tables that follow the document: links, entity mentions,
     * checklist actions.
     *
     * A private note is skipped entirely. Its document is ciphertext the server
     * cannot read, so extracting links or checklist items from it would produce
     * nonsense — and attempting it would be the first step towards indexing
     * content the user was promised is unreadable here.
     */
    private function syncDerived(Identity $identity, string $noteId, array $document, string $privacyMode): void
    {
        if ($privacyMode === 'private') {
            return;
        }

        $this->links->sync($noteId, $document);
        $this->links->syncEntityMentions($identity, $noteId, $document);
        $this->actions->syncFromDocument($identity, $noteId, $document);
    }

    /** @param array<string, mixed> $note @param array<string, mixed> $input */
    private function recordStateActivity(Identity $identity, string $noteId, array $note, array $input): void
    {
        if (array_key_exists('is_archived', $input) && (bool) $input['is_archived'] !== (bool) $note['is_archived']) {
            $this->activity->record(
                $identity,
                $input['is_archived'] ? ActivityRecorder::NOTE_ARCHIVED : ActivityRecorder::NOTE_UNARCHIVED,
                $noteId,
            );

            return;
        }

        if (array_key_exists('notebook_id', $input)
            && (string) ($input['notebook_id'] ?? '') !== (string) ($note['notebook_id'] ?? '')) {
            $this->activity->record($identity, ActivityRecorder::NOTE_MOVED, $noteId, null, [
                'to_notebook_id' => $input['notebook_id'],
            ]);

            return;
        }

        if (array_key_exists('document', $input) || array_key_exists('title', $input)) {
            $this->activity->record($identity, ActivityRecorder::NOTE_UPDATED, $noteId);
        }
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /** Soft delete — the note goes to Trash and stays restorable. */
    public function trash(Identity $identity, string $noteId): void
    {
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::MANAGE);

        Connection::execute(
            'UPDATE notes SET deleted_at = now(), updated_by = :actor WHERE id = :id AND deleted_at IS NULL',
            ['id' => $noteId, 'actor' => $identity->userId],
        );
        $this->activity->record($identity, ActivityRecorder::NOTE_TRASHED, $noteId);
    }

    public function restore(Identity $identity, string $noteId): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::MANAGE,
            includeTrashed: true,
        );

        Connection::execute(
            'UPDATE notes SET deleted_at = NULL, updated_by = :actor, updated_at = now() WHERE id = :id',
            ['id' => $noteId, 'actor' => $identity->userId],
        );
        $this->activity->record($identity, ActivityRecorder::NOTE_RESTORED, $noteId);

        return $this->get($identity, $noteId);
    }

    /** Irreversible. Only reachable from Trash, and only for the owner. */
    public function purge(Identity $identity, string $noteId): void
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::MANAGE,
            includeTrashed: true,
        );

        if ($note['deleted_at'] === null) {
            throw ApiException::badRequest('Move the note to Trash before deleting it permanently.');
        }

        // Everything that references a note cascades, so this one statement
        // takes the revisions, comments, attachments rows and links with it.
        Connection::execute('DELETE FROM notes WHERE id = :id', ['id' => $noteId]);
        $this->activity->record($identity, ActivityRecorder::NOTE_DELETED, null, null, ['note_id' => $noteId]);
    }

    /**
     * Copy a note.
     *
     * Document, title, tags, notebook and colour come across. Comments,
     * collaborators, activity, reminders and version history do not — those
     * belong to the original, and duplicating them would mean silently
     * re-sharing a note or double-firing a reminder.
     */
    public function duplicate(Identity $identity, string $noteId): array
    {
        $note = $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW);

        $document = $note['document_json'];
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : NoteDocument::empty();
        }

        $title = $note['title'] === null ? null : Str::limit((string) $note['title'] . ' (copy)', 500);

        // The copy is yours; the notebook it came from may not be. A note
        // shared with you usually sits in the owner's notebook, and filing
        // into that notebook needs edit rights on it — so carrying the id over
        // unconditionally answered 404 for a notebook the duplicating user had
        // never been told about and could do nothing with. Where they can file
        // there, the copy lands beside the original; where they cannot, it
        // lands unfiled, which is the only other honest place for it.
        $notebookId = $note['notebook_id'];
        if ($notebookId !== null && !$this->canFileInto($identity, (string) $notebookId)) {
            $notebookId = null;
        }

        $copy = $this->create($identity, [
            'title' => $title,
            'document' => $document,
            'note_type' => (string) $note['note_type'],
            'notebook_id' => $notebookId,
            'color' => $note['color'],
            'tags' => $this->tags->namesForNote($noteId),
            'source' => 'duplicate',
        ]);

        $this->activity->record($identity, ActivityRecorder::NOTE_DUPLICATED, $copy['id'], null, [
            'source_note_id' => $noteId,
        ]);

        return $copy;
    }

    /**
     * Empty the trash of notes past the retention window.
     *
     * Run from the worker. Returns how many rows went.
     */
    public function purgeExpiredTrash(): int
    {
        $days = Features::int('NOTES_TRASH_RETENTION_DAYS', 30, 1, 3650);

        return Connection::execute(
            'DELETE FROM notes WHERE deleted_at IS NOT NULL
             AND deleted_at < now() - make_interval(days => :days)',
            ['days' => $days],
        );
    }

    // -----------------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------------

    private function noteType(mixed $value): string
    {
        $type = strtolower((string) (is_scalar($value) ? $value : 'document'));
        if (!in_array($type, self::NOTE_TYPES, true)) {
            throw ApiException::validation(['note_type' => 'Unknown note type.']);
        }
        if ($type === 'canvas' && !Features::enabled(Features::CANVAS)) {
            throw ApiException::featureDisabled(Features::CANVAS);
        }

        return $type;
    }

    private function privacyMode(mixed $value): string
    {
        $mode = strtolower((string) (is_scalar($value) ? $value : 'standard'));
        if ($mode === 'private') {
            // Honesty rule: a deployment without the client-side key management
            // must not be able to *label* a note end-to-end encrypted.
            Features::require(Features::PRIVATE_NOTES);

            return 'private';
        }

        return 'standard';
    }

    private function title(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $title = trim((string) (is_scalar($value) ? $value : ''));

        // An empty title is legitimate — the first line becomes the display
        // title — so it is stored as NULL rather than rejected.
        return $title === '' ? null : Str::limit($title, 500);
    }

    private function color(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'default') {
            return null;
        }
        $color = strtolower((string) $value);
        if (!in_array($color, self::COLORS, true)) {
            throw ApiException::validation(['color' => 'Unknown note colour.']);
        }

        return $color;
    }

    /** A notebook the caller may file into, or null. */
    /**
     * May this caller put a note into that notebook?
     *
     * A question, not a demand: {@see resolveNotebook()} raises when the
     * answer is no, which is right when the user named the notebook and wrong
     * when the code inferred it.
     */
    private function canFileInto(Identity $identity, string $notebookId): bool
    {
        try {
            $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::EDIT);
        } catch (ApiException) {
            return false;
        }

        return true;
    }

    private function resolveNotebook(Identity $identity, mixed $notebookId): ?string
    {
        if ($notebookId === null || $notebookId === '') {
            return null;
        }
        if (!Uuid::isValid($notebookId)) {
            throw ApiException::validation(['notebook_id' => 'That is not a notebook id.']);
        }

        // Filing a note into someone else's notebook is a write to that
        // notebook, so it needs edit rights on it.
        $this->permissions->requireNotebook($identity, (string) $notebookId, NotePermissionService::EDIT);

        return strtolower((string) $notebookId);
    }

    /** @return array<int, array<string, mixed>> */
    private function tagsFor(string $noteId): array
    {
        $rows = Connection::select(
            'SELECT t.id, t.name, t.slug, t.color FROM note_tags nt
             JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = :id ORDER BY t.name',
            ['id' => $noteId],
        );

        return array_map(static fn (array $row) => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
        ], $rows);
    }

    private function scalar(string $sql, string $noteId): int
    {
        $row = Connection::selectOne($sql, ['id' => $noteId]);

        return (int) array_values($row ?? [0])[0];
    }
}
