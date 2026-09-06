<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Version history.
 *
 * Autosave fires every few seconds, so the naive design — one revision per save —
 * would produce thousands of rows for one afternoon's writing and a history
 * nobody can read. Instead revisions are *checkpointed*:
 *
 *   - An unchanged document (same content hash) writes nothing at all.
 *   - Consecutive autosaves by the same author inside the coalesce window
 *     (default 10 minutes) rewrite the latest revision in place, so a writing
 *     session collapses to one entry that keeps moving forward.
 *   - A different author, a gap longer than the window, or an explicit reason
 *     (`manual`, `restore`, `import`) always starts a new revision.
 *
 * The result is a history with one entry per meaningful checkpoint, which is
 * what "restore an earlier version" actually needs.
 */
final class NoteRevisionService
{
    private const DEFAULT_COALESCE_MINUTES = 10;
    private const DEFAULT_KEEP = 100;

    /**
     * Record a checkpoint if this content deserves one.
     *
     * @return string|null The revision id, or null when nothing was written.
     */
    public function capture(
        string $noteId,
        ?string $title,
        array $document,
        string $extractedText,
        string $contentHash,
        string $userId,
        string $reason = 'autosave',
    ): ?string {
        $latest = Connection::selectOne(
            'SELECT id, revision_number, content_hash, created_by, created_at, reason
             FROM note_revisions WHERE note_id = :note_id
             ORDER BY revision_number DESC LIMIT 1',
            ['note_id' => $noteId],
        );

        // Identical content is not a version.
        if ($latest !== null && (string) $latest['content_hash'] === $contentHash) {
            return null;
        }

        $payload = [
            'title' => $title,
            'document_json' => json_encode($document, JSON_UNESCAPED_SLASHES),
            'extracted_text' => $extractedText,
            'content_hash' => $contentHash,
        ];

        if ($reason === 'autosave' && $latest !== null && $this->withinCoalesceWindow($latest, $userId)) {
            Connection::execute(
                'UPDATE note_revisions
                 SET title = :title, document_json = :document_json::jsonb,
                     extracted_text = :extracted_text, content_hash = :content_hash,
                     created_at = now()
                 WHERE id = :id',
                $payload + ['id' => $latest['id']],
            );

            return (string) $latest['id'];
        }

        $revisionId = Uuid::v4();
        Connection::execute(
            'INSERT INTO note_revisions
                (id, note_id, revision_number, title, document_json, extracted_text,
                 content_hash, reason, created_by)
             VALUES
                (:id, :note_id, :revision_number, :title, :document_json::jsonb, :extracted_text,
                 :content_hash, :reason, :created_by)',
            $payload + [
                'id' => $revisionId,
                'note_id' => $noteId,
                'revision_number' => (int) ($latest['revision_number'] ?? 0) + 1,
                'reason' => $reason,
                'created_by' => $userId,
            ],
        );

        $this->prune($noteId);

        return $revisionId;
    }

    /** @param array<string, mixed> $latest */
    private function withinCoalesceWindow(array $latest, string $userId): bool
    {
        // Only an autosave may be rewritten. A manual checkpoint, a restore
        // point or an import is a version somebody deliberately created, and
        // folding the next autosave into it would erase it from the history —
        // the one thing version history exists to prevent.
        if ((string) $latest['reason'] !== 'autosave') {
            return false;
        }

        if ((string) $latest['created_by'] !== $userId) {
            // Someone else's checkpoint is never overwritten — that would erase
            // a collaborator's version from the history.
            return false;
        }

        try {
            $age = Clock::now()->getTimestamp() - (new \DateTimeImmutable((string) $latest['created_at']))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }

        return $age < Features::int('NOTES_REVISION_COALESCE_MINUTES', self::DEFAULT_COALESCE_MINUTES, 1, 120) * 60;
    }

    /**
     * Keep the newest N revisions.
     *
     * Explicit checkpoints — a manual save, a restore point, an import — are
     * never pruned: they are the ones a user deliberately created and expects
     * to find later.
     */
    private function prune(string $noteId): void
    {
        $keep = Features::int('NOTES_REVISION_KEEP', self::DEFAULT_KEEP, 10, 1000);

        Connection::execute(
            'DELETE FROM note_revisions
             WHERE note_id = :note_id
               AND reason = \'autosave\'
               AND id NOT IN (
                   SELECT id FROM note_revisions
                   WHERE note_id = :note_id
                   ORDER BY revision_number DESC
                   LIMIT :keep
               )',
            ['note_id' => $noteId, 'keep' => $keep],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listForNote(string $noteId, int $limit = 50, int $offset = 0): array
    {
        $rows = Connection::select(
            'SELECT id, revision_number, title, reason, created_by, created_at,
                    length(extracted_text) AS text_length
             FROM note_revisions
             WHERE note_id = :note_id
             ORDER BY revision_number DESC
             LIMIT :limit OFFSET :offset',
            ['note_id' => $noteId, 'limit' => $limit, 'offset' => $offset],
        );

        return array_map(static fn (array $row) => [
            'id' => (string) $row['id'],
            'revision_number' => (int) $row['revision_number'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'reason' => (string) $row['reason'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'size' => (int) $row['text_length'],
        ], $rows);
    }

    /** @return array<string, mixed> */
    public function get(string $noteId, string $revisionId): array
    {
        $row = Connection::selectOne(
            'SELECT * FROM note_revisions WHERE id = :id AND note_id = :note_id',
            ['id' => $revisionId, 'note_id' => $noteId],
        );

        if ($row === null) {
            throw ApiException::notFound('That version');
        }

        $document = json_decode((string) $row['document_json'], true);

        return [
            'id' => (string) $row['id'],
            'revision_number' => (int) $row['revision_number'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'document' => is_array($document) ? $document : NoteDocument::empty(),
            'reason' => (string) $row['reason'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Total number of revisions, for the history drawer's pagination.
     */
    public function countForNote(string $noteId): int
    {
        $row = Connection::selectOne(
            'SELECT count(*) AS total FROM note_revisions WHERE note_id = :note_id',
            ['note_id' => $noteId],
        );

        return (int) ($row['total'] ?? 0);
    }
}
