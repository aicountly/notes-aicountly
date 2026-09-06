<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Comments on a note.
 *
 * Three decisions shape everything below.
 *
 *   - **A comment is never lost to an edit.** A comment anchors to a block id
 *     and keeps a copy of the text that block held when it was written. When
 *     the block is edited away the comment does not disappear: it comes back
 *     flagged `orphaned`, with its `anchor_text`, so the reader can still see
 *     what was being discussed. Dropping it would delete one person's words
 *     because a different person rewrote a paragraph.
 *   - **Threads are one level deep.** A reply to a reply is re-pointed at the
 *     thread it belongs to — see {@see self::resolveParent()}.
 *   - **Deletion is soft, and moderation is not editing.** The author may
 *     retract their own comment and the note owner may remove anybody's, but
 *     nobody may rewrite somebody else's words: editing is the author's alone.
 *
 * Nothing here answers "may they?" on its own. Every entry point resolves the
 * comment's note through {@see NotePermissionService}, so a comment on a note
 * the caller cannot open is indistinguishable from a comment that does not
 * exist.
 */
final class CommentService
{
    /** Long enough for a paragraph of review; short enough that a body is not a document. */
    private const MAX_BODY = 10000;
    private const MAX_ANCHOR_TEXT = 500;
    /** `note_comments.block_id` is varchar(64). */
    private const MAX_BLOCK_ID = 64;
    private const MAX_MENTIONS = 50;
    private const MAX_MENTION_ID = 128;

    /**
     * A cap on one note's comment panel, so a note somebody has been arguing
     * on for a year cannot turn a page load into a megabyte of JSON.
     */
    private const MAX_PER_NOTE = 500;

    /** Guardrail for the block-id scan, matching the document sanitiser's own depth cap. */
    private const MAX_DOCUMENT_DEPTH = 40;

    // The recorder's `action` column is free-form and its own constants cover
    // adding and resolving; these are the rest of a comment's life.
    private const ACTIVITY_UPDATED = 'comment.updated';
    private const ACTIVITY_REOPENED = 'comment.reopened';
    private const ACTIVITY_DELETED = 'comment.deleted';

    /**
     * Columns a note fetch needs here: the privacy gate, the owner (who may
     * moderate), and — where a comment is about to be presented — the document
     * the anchors are checked against. A note document can be megabytes, so the
     * paths that present nothing ask for the short list.
     */
    private const NOTE_COLUMNS = 'n.id, n.privacy_mode, n.owner_user_id, n.document_json';
    private const NOTE_COLUMNS_LIGHT = 'n.id, n.privacy_mode, n.owner_user_id';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * One note's comments, as threads.
     *
     * @param array<string, mixed> $options `resolved` (all|open|resolved) and `block_id`.
     * @return array{threads: array<int, array<string, mixed>>, total: int, unresolved: int, has_more: bool}
     */
    public function listForNote(Identity $identity, string $noteId, array $options = []): array
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: self::NOTE_COLUMNS,
        );

        $rows = Connection::select(
            // The access CTE is joined even though the check above already
            // passed. It is the gate every read composes, and a query that
            // trusts its caller to have checked first is one refactor away
            // from being the endpoint that leaked.
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT c.id, c.parent_id, c.block_id, c.anchor_text, c.body, c.author_user_id,
                    c.mentions, c.resolved_at, c.resolved_by, c.created_at, c.updated_at
             FROM note_comments c
             JOIN note_access a ON a.note_id = c.note_id
             WHERE c.note_id = :note_id AND c.deleted_at IS NULL
             -- Oldest first: a thread reads as a conversation, not as a feed.
             ORDER BY c.created_at, c.id
             LIMIT :limit',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'note_id' => $noteId,
                // One more than the cap, so "there are more" is known without
                // a second counting query — and is reported rather than left
                // to look like the note simply has no further comments.
                'limit' => self::MAX_PER_NOTE + 1,
            ],
        );

        $hasMore = count($rows) > self::MAX_PER_NOTE;
        $rows = array_slice($rows, 0, self::MAX_PER_NOTE);

        $context = $this->presentationContext($identity, $note, $rows);
        $comments = array_map(static fn (array $row): array => self::present($row, $context), $rows);

        return [
            'threads' => self::filterThreads(self::nest($comments), $options),
            // Counted before the filter is applied: a panel showing "2 open"
            // while filtered to the resolved ones is the number a person
            // actually wants.
            'total' => count($comments),
            'unresolved' => count(array_filter(
                $comments,
                static fn (array $c): bool => $c['parent_id'] === null && !$c['is_resolved'],
            )),
            'has_more' => $hasMore,
        ];
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, string $noteId, array $input): array
    {
        // COMMENT, not EDIT: a commenter may say something about the note
        // without being able to change a word of it.
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::COMMENT,
            columns: self::NOTE_COLUMNS,
        );

        $commentId = Uuid::v4();
        $body = $this->body($input['body'] ?? null);
        $parentId = $this->resolveParent($noteId, $input['parent_id'] ?? null);
        $blockId = self::blockId($input['block_id'] ?? null);
        $mentions = self::mentions($input['mentions'] ?? null);

        Connection::execute(
            'INSERT INTO note_comments
                (id, note_id, parent_id, block_id, anchor_text, body, author_user_id, mentions)
             VALUES
                (:id, :note_id, :parent_id, :block_id, :anchor_text, :body, :author, :mentions::jsonb)',
            [
                'id' => $commentId,
                'note_id' => $noteId,
                'parent_id' => $parentId,
                'block_id' => $blockId,
                'anchor_text' => self::anchorText($input['anchor_text'] ?? null),
                'body' => $body,
                'author' => $identity->userId,
                'mentions' => (string) json_encode($mentions),
            ],
        );

        // Ids and counts only. The trail is shown to every collaborator on the
        // note, and a comment body is content — as is the anchor text, which
        // is a quotation of the note.
        $this->activity->record($identity, ActivityRecorder::COMMENT_ADDED, $noteId, null, [
            'comment_id' => $commentId,
            'parent_id' => $parentId,
            'anchored' => $blockId !== null,
            'mention_count' => count($mentions),
        ]);

        return $this->reload($identity, $note, $commentId);
    }

    /**
     * Edit a comment's own text.
     *
     * The author's alone, even for the note owner: deleting somebody's comment
     * removes their words, editing it replaces them with yours under their
     * name. The first is moderation; the second is forgery.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $commentId, array $input): array
    {
        [$comment, $note] = $this->load($identity, $commentId, NotePermissionService::COMMENT);

        if (strcasecmp((string) $comment['author_user_id'], $identity->userId) !== 0) {
            throw ApiException::forbidden(
                'COMMENT_NOT_AUTHOR',
                'Only the person who wrote a comment can edit it.',
            );
        }

        $updates = ['body = :body', 'updated_at = now()'];
        $bindings = ['id' => $commentId, 'body' => $this->body($input['body'] ?? null)];

        // Absent leaves the mentions alone; an empty array clears them. The
        // editor re-sends what it parsed out of the edited text, so an omitted
        // key must not be read as "there are none".
        if (array_key_exists('mentions', $input)) {
            $updates[] = 'mentions = :mentions::jsonb';
            $bindings['mentions'] = (string) json_encode(self::mentions($input['mentions']));
        }

        Connection::execute(
            'UPDATE note_comments SET ' . implode(', ', $updates) . '
             WHERE id = :id AND deleted_at IS NULL',
            $bindings,
        );

        $this->activity->record($identity, self::ACTIVITY_UPDATED, (string) $comment['note_id'], null, [
            'comment_id' => $commentId,
        ]);

        return $this->reload($identity, $note, $commentId);
    }

    /** @return array<string, mixed> The thread, now closed. */
    public function resolve(Identity $identity, string $commentId): array
    {
        return $this->setResolved($identity, $commentId, true);
    }

    /** @return array<string, mixed> The thread, open again. */
    public function reopen(Identity $identity, string $commentId): array
    {
        return $this->setResolved($identity, $commentId, false);
    }

    /**
     * Retract a comment, or moderate one.
     *
     * Soft: the row stays so a restored note keeps its history, and every
     * listing here filters `deleted_at IS NULL`.
     */
    public function delete(Identity $identity, string $commentId): void
    {
        // VIEW rather than COMMENT: someone downgraded to viewer after writing
        // a comment must still be able to take their own words back.
        [$comment, $note] = $this->load($identity, $commentId, NotePermissionService::VIEW, self::NOTE_COLUMNS_LIGHT);

        $isAuthor = strcasecmp((string) $comment['author_user_id'], $identity->userId) === 0;
        $isOwner = strcasecmp((string) $note['owner_user_id'], $identity->userId) === 0;

        if (!$isAuthor && !$isOwner) {
            throw ApiException::forbidden(
                'COMMENT_DELETE_DENIED',
                'Only the person who wrote a comment, or the note owner, can delete it.',
            );
        }

        // Deleting the comment that opened a thread takes its replies with it:
        // a reply without the sentence it answers is unreadable, and leaving
        // it behind would show an answer whose question has been withdrawn.
        // Both placeholders are cast so Postgres deduces one type for the
        // repeated parameter instead of refusing the statement.
        $removed = Connection::execute(
            'UPDATE note_comments SET deleted_at = now()
             WHERE (id = :id::uuid OR parent_id = :id::uuid) AND deleted_at IS NULL',
            ['id' => $commentId],
        );

        $this->activity->record($identity, self::ACTIVITY_DELETED, (string) $comment['note_id'], null, [
            'comment_id' => $commentId,
            'replies_removed' => max(0, $removed - 1),
            'by_owner' => !$isAuthor,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function setResolved(Identity $identity, string $commentId, bool $resolved): array
    {
        // Resolving is participation, so it needs COMMENT: a viewer can read a
        // discussion but cannot declare it finished.
        [$comment, $note] = $this->load($identity, $commentId, NotePermissionService::COMMENT);

        // Resolution belongs to the thread, not to one message in it. Clicking
        // resolve on a reply means "this discussion is done", and a thread
        // whose root stayed open while a reply was resolved would render as
        // still open — the click would look like it did nothing.
        $rootId = $comment['parent_id'] === null ? $commentId : (string) $comment['parent_id'];

        Connection::execute(
            // `updated_at` deliberately untouched: it means "the text changed",
            // which is what the edited badge is derived from.
            // `:resolved` is cast the same way in both branches; an uncast
            // repeat is what makes Postgres refuse with "inconsistent types
            // deduced for parameter".
            'UPDATE note_comments
                SET resolved_at = CASE WHEN :resolved::boolean THEN now() ELSE NULL END,
                    resolved_by = CASE WHEN :resolved::boolean THEN :actor::text ELSE NULL END
             WHERE id = :id AND deleted_at IS NULL',
            ['resolved' => $resolved, 'actor' => $identity->userId, 'id' => $rootId],
        );

        $this->activity->record(
            $identity,
            $resolved ? ActivityRecorder::COMMENT_RESOLVED : self::ACTIVITY_REOPENED,
            (string) $comment['note_id'],
            null,
            ['comment_id' => $rootId],
        );

        return $this->reload($identity, $note, $rootId);
    }

    /**
     * The comment plus the note it lives on, or the failure that hides both.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function load(
        Identity $identity,
        string $commentId,
        string $capability,
        string $columns = self::NOTE_COLUMNS,
    ): array {
        $comment = Connection::selectOne(
            'SELECT id, note_id, parent_id, author_user_id FROM note_comments
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $commentId],
        );
        if ($comment === null) {
            throw ApiException::notFound('That comment');
        }

        // The note is what carries access, so the answer for a comment on
        // somebody else's note is the same 404 that note itself would give.
        $note = $this->permissions->requireNote(
            $identity,
            (string) $comment['note_id'],
            $capability,
            columns: $columns,
        );

        return [$comment, $note];
    }

    /**
     * Re-read one comment (with its replies) the way a listing would present it.
     *
     * @param array<string, mixed> $note
     * @return array<string, mixed>
     */
    private function reload(Identity $identity, array $note, string $commentId): array
    {
        $rows = Connection::select(
            'SELECT id, parent_id, block_id, anchor_text, body, author_user_id, mentions,
                    resolved_at, resolved_by, created_at, updated_at
             FROM note_comments
             WHERE (id = :id::uuid OR parent_id = :id::uuid) AND deleted_at IS NULL
             ORDER BY created_at, id',
            ['id' => $commentId],
        );

        if ($rows === []) {
            throw ApiException::notFound('That comment');
        }

        $context = $this->presentationContext($identity, $note, $rows);
        $threads = self::nest(array_map(static fn (array $row): array => self::present($row, $context), $rows));

        // A reply fetched on its own is its own root in that set, so this is
        // always the comment that was asked for.
        return $threads[0];
    }

    /**
     * What presentation needs beyond the row: who is looking, what they may do,
     * and which anchors the document still has.
     *
     * @param array<string, mixed> $note
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function presentationContext(Identity $identity, array $note, array $rows): array
    {
        $anchored = false;
        foreach ($rows as $row) {
            if (($row['block_id'] ?? null) !== null) {
                $anchored = true;
                break;
            }
        }

        return [
            'viewer' => $identity->userId,
            'role' => (string) ($note['_role'] ?? NoteRole::VIEWER),
            'owner' => (string) ($note['owner_user_id'] ?? ''),
            // Scanning the document costs a walk of the tree, so it happens
            // only when something is actually anchored to a block.
            'block_ids' => $anchored ? self::documentBlockIds($note['document_json'] ?? null) : [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function present(array $row, array $context): array
    {
        $blockId = $row['block_id'] === null ? null : (string) $row['block_id'];
        $author = (string) $row['author_user_id'];
        $isAuthor = strcasecmp($author, (string) $context['viewer']) === 0;
        $isOwner = strcasecmp((string) $context['owner'], (string) $context['viewer']) === 0;
        $mayComment = NoteRole::atLeast((string) $context['role'], NoteRole::COMMENTER);

        return [
            'id' => (string) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'block_id' => $blockId,
            'anchor_text' => $row['anchor_text'] === null ? null : (string) $row['anchor_text'],
            // The block this comment pointed at is gone from the document. The
            // comment is still returned — flagged, so the client can show it
            // in an "elsewhere in this note" group instead of trying to scroll
            // to a block that no longer exists.
            'orphaned' => $blockId !== null && !isset($context['block_ids'][strtolower($blockId)]),
            'body' => (string) $row['body'],
            'author_user_id' => $author,
            'mentions' => self::decodeMentions($row['mentions'] ?? null),
            'is_resolved' => ($row['resolved_at'] ?? null) !== null,
            'resolved_at' => self::timestamp($row['resolved_at'] ?? null),
            'resolved_by' => $row['resolved_by'] === null ? null : (string) $row['resolved_by'],
            'edited' => ($row['updated_at'] ?? null) !== ($row['created_at'] ?? null),
            'capabilities' => [
                'edit' => $isAuthor && $mayComment,
                'delete' => $isAuthor || $isOwner,
                'resolve' => $mayComment,
                'reply' => $mayComment,
            ],
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
            // Always present, and always empty on a reply: threads are one
            // level deep, so the client never recurses.
            'replies' => [],
        ];
    }

    /**
     * Group replies under the comment they answer.
     *
     * @param array<int, array<string, mixed>> $comments Presented, oldest first.
     * @return array<int, array<string, mixed>>
     */
    private static function nest(array $comments): array
    {
        $roots = [];
        $replies = [];

        foreach ($comments as $comment) {
            $parentId = $comment['parent_id'];
            if ($parentId === null) {
                $roots[$comment['id']] = $comment;
                continue;
            }
            $replies[$parentId][] = $comment;
        }

        foreach ($replies as $parentId => $children) {
            if (isset($roots[$parentId])) {
                $roots[$parentId]['replies'] = $children;
                continue;
            }
            // A reply whose root is not in this set — a page cut short, or a
            // root deleted by an older build that did not cascade. Showing it
            // as its own thread keeps somebody's words on screen; dropping it
            // would lose them silently.
            foreach ($children as $orphanReply) {
                $roots[$orphanReply['id']] = $orphanReply;
            }
        }

        return array_values($roots);
    }

    /**
     * @param array<int, array<string, mixed>> $threads
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private static function filterThreads(array $threads, array $options): array
    {
        $resolved = (string) ($options['resolved'] ?? 'all');
        $blockId = isset($options['block_id']) && is_string($options['block_id']) && $options['block_id'] !== ''
            ? strtolower($options['block_id'])
            : null;

        // Filtering is by thread, not by comment: a reply is only meaningful
        // under the comment it answers, so it travels with its root or not at
        // all.
        return array_values(array_filter($threads, static function (array $thread) use ($resolved, $blockId): bool {
            if ($resolved === 'open' && $thread['is_resolved']) {
                return false;
            }
            if ($resolved === 'resolved' && !$thread['is_resolved']) {
                return false;
            }

            return $blockId === null
                || ($thread['block_id'] !== null && strtolower((string) $thread['block_id']) === $blockId);
        }));
    }

    /**
     * The thread a new comment belongs to.
     *
     * A reply to a reply is re-pointed at the thread root rather than refused.
     * The panel renders a thread, not a tree: somebody clicking reply under a
     * reply means "reply in this conversation", which has exactly one sensible
     * home. Refusing would raise an error for an act with an obvious correct
     * reading; storing the nesting would produce a shape the client cannot draw.
     */
    private function resolveParent(string $noteId, mixed $parentId): ?string
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        if (!Uuid::isValid($parentId)) {
            throw ApiException::validation(['parent_id' => 'That is not a comment id.']);
        }

        $parent = Connection::selectOne(
            // Scoped to this note, so a comment id from a note the caller can
            // see cannot be used to hang a reply on a note they cannot.
            'SELECT id, parent_id FROM note_comments
             WHERE id = :id AND note_id = :note_id AND deleted_at IS NULL',
            ['id' => strtolower((string) $parentId), 'note_id' => $noteId],
        );
        if ($parent === null) {
            throw ApiException::notFound('That comment');
        }

        return $parent['parent_id'] === null ? (string) $parent['id'] : (string) $parent['parent_id'];
    }

    private function body(mixed $value): string
    {
        $body = is_scalar($value) ? trim((string) $value) : '';
        if ($body === '') {
            throw ApiException::validation(['body' => 'A comment needs something in it.']);
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_BODY) {
            // Refused rather than truncated: silently cutting a comment in
            // half discards what somebody wrote and tells them it saved.
            throw ApiException::validation([
                'body' => 'A comment cannot be longer than ' . self::MAX_BODY . ' characters.',
            ]);
        }

        return $body;
    }

    private static function blockId(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $blockId = trim((string) $value);
        if ($blockId === '') {
            return null;
        }

        // Block ids the sanitiser mints are lower-case UUIDs; anything else is
        // stored as sent and will simply read as orphaned, which is the honest
        // answer for an anchor the document does not contain.
        return Uuid::isValid($blockId) ? strtolower($blockId) : Str::limit($blockId, self::MAX_BLOCK_ID);
    }

    private static function anchorText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : Str::limit($text, self::MAX_ANCHOR_TEXT);
    }

    /**
     * Mentioned entity ids, deduped.
     *
     * Ids only — an AICOUNTLY record is described by the service that owns it,
     * and a label copied in here would be stale the moment that record is
     * renamed.
     *
     * @return array<int, string>
     */
    private static function mentions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $entry) {
            // The editor may send bare ids or the mention nodes it holds; both
            // reduce to the same thing.
            $id = is_array($entry) ? ($entry['entity_id'] ?? $entry['id'] ?? null) : $entry;
            if (!is_scalar($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $ids[Str::limit($id, self::MAX_MENTION_ID)] = true;
            if (count($ids) >= self::MAX_MENTIONS) {
                break;
            }
        }

        return array_keys($ids);
    }

    /** @return array<int, string> */
    private static function decodeMentions(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $value),
            static fn (string $id): bool => $id !== '',
        ));
    }

    /**
     * Every block id the document currently contains.
     *
     * This walks the tree here rather than through {@see \Aicountly\Api\Domain\Notes\NoteDocument},
     * whose walker is private: comments are the only reader that needs the ids,
     * and a comment package does not get to widen the document class's surface.
     *
     * @return array<string, true> Keyed by id, for O(1) lookup per comment.
     */
    private static function documentBlockIds(mixed $document, int $depth = 0): array
    {
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($document) || $depth > self::MAX_DOCUMENT_DEPTH) {
            return [];
        }

        $ids = [];
        $blockId = $document['attrs']['blockId'] ?? null;
        if (is_scalar($blockId) && (string) $blockId !== '') {
            $ids[strtolower((string) $blockId)] = true;
        }

        foreach (($document['content'] ?? []) as $child) {
            if (is_array($child)) {
                $ids += self::documentBlockIds($child, $depth + 1);
            }
        }

        return $ids;
    }

    /**
     * Postgres' text form is not something a browser parses reliably, so the
     * wire carries RFC 3339 — the same shape a note's timestamps arrive in.
     */
    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            return null;
        }
    }
}
