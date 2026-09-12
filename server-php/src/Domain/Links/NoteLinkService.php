<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Links;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Support\Uuid;

/**
 * `[[wiki links]]` between notes, and the backlinks that fall out of them.
 *
 * Links are reconciled into rows on every save rather than parsed out of the
 * document when a page renders. That is what makes renaming a note safe — the
 * row points at an id, so the link survives — and it is what lets "what points
 * at this note?" be an indexed lookup instead of a scan of every document.
 */
final class NoteLinkService
{
    /**
     * Make `note_links` match the document.
     *
     * Links to notes that do not exist are dropped: a dangling foreign key is
     * not a link, and storing one would break the FK anyway.
     */
    public function sync(string $sourceNoteId, array $document): void
    {
        $links = \Aicountly\Api\Domain\Notes\NoteDocument::extractNoteLinks($document);

        // De-duplicate on the same key the unique index uses.
        $wanted = [];
        foreach ($links as $link) {
            if ($link['note_id'] === $sourceNoteId) {
                continue;
            }
            $wanted[$link['note_id'] . '|' . ($link['block_id'] ?? '')] = $link;
        }

        Connection::transaction(static function () use ($sourceNoteId, $wanted): void {
            Connection::execute('DELETE FROM note_links WHERE source_note_id = :id', ['id' => $sourceNoteId]);

            foreach ($wanted as $link) {
                // ON CONFLICT DO NOTHING covers the case where two blocks carry
                // the same (target, block) pair after a paste.
                Connection::execute(
                    'INSERT INTO note_links (id, source_note_id, target_note_id, source_block_id, label)
                     SELECT :id, :source, :target, :block, :label
                     WHERE EXISTS (SELECT 1 FROM notes WHERE id = :target AND deleted_at IS NULL)
                     ON CONFLICT DO NOTHING',
                    [
                        'id' => Uuid::v4(),
                        'source' => $sourceNoteId,
                        'target' => $link['note_id'],
                        'block' => $link['block_id'],
                        'label' => $link['label'],
                    ],
                );
            }
        });
    }

    /**
     * Notes that link **to** this one — "Linked references".
     *
     * Permission-filtered: a backlink from a note the caller cannot open must
     * not appear, or the panel becomes a way to enumerate other people's notes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function backlinks(Identity $identity, string $noteId, int $limit = 50): array
    {
        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT DISTINCT ON (n.id)
                    n.id, n.title, n.note_type, n.updated_at,
                    left(n.extracted_text, 240) AS extracted_text,
                    l.source_block_id, l.label
             FROM note_links l
             JOIN notes n ON n.id = l.source_note_id AND n.deleted_at IS NULL
             JOIN note_access a ON a.note_id = n.id
             WHERE l.target_note_id = :note_id
             ORDER BY n.id, n.updated_at DESC
             LIMIT :limit',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'note_id' => $noteId,
                'limit' => $limit,
            ],
        );

        return array_map(static fn (array $row) => [
            'note_id' => (string) $row['id'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'note_type' => (string) $row['note_type'],
            'excerpt' => (string) $row['extracted_text'],
            'block_id' => $row['source_block_id'] === null ? null : (string) $row['source_block_id'],
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'updated_at' => (string) $row['updated_at'],
        ], $rows);
    }

    /** Notes this one links out to. */
    public function outgoing(Identity $identity, string $noteId, int $limit = 50): array
    {
        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT DISTINCT ON (n.id) n.id, n.title, n.note_type, n.updated_at
             FROM note_links l
             JOIN notes n ON n.id = l.target_note_id AND n.deleted_at IS NULL
             JOIN note_access a ON a.note_id = n.id
             WHERE l.source_note_id = :note_id
             ORDER BY n.id, n.updated_at DESC
             LIMIT :limit',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'note_id' => $noteId,
                'limit' => $limit,
            ],
        );

        return array_map(static fn (array $row) => [
            'note_id' => (string) $row['id'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'note_type' => (string) $row['note_type'],
            'updated_at' => (string) $row['updated_at'],
        ], $rows);
    }

    public function backlinkCount(Identity $identity, string $noteId): int
    {
        $row = Connection::selectOne(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT count(DISTINCT l.source_note_id) AS total
             FROM note_links l
             JOIN notes n ON n.id = l.source_note_id AND n.deleted_at IS NULL
             JOIN note_access a ON a.note_id = n.id
             WHERE l.target_note_id = :note_id',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'note_id' => $noteId,
            ],
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Reconcile `note_entity_links` from `mention` nodes in the document.
     *
     * Mentions added by hand through the API are kept: this only removes rows
     * it created itself, identified by the `via_document` metadata flag.
     */
    public function syncEntityMentions(Identity $identity, string $noteId, array $document): void
    {
        $mentions = \Aicountly\Api\Domain\Notes\NoteDocument::extractEntityMentions($document);

        Connection::transaction(static function () use ($identity, $noteId, $mentions): void {
            Connection::execute(
                "DELETE FROM note_entity_links
                 WHERE note_id = :note_id AND metadata->>'via_document' = 'true'",
                ['note_id' => $noteId],
            );

            foreach ($mentions as $mention) {
                Connection::execute(
                    'INSERT INTO note_entity_links (id, note_id, entity_type, entity_id, label, metadata, created_by)
                     VALUES (:id, :note_id, :type, :entity_id, :label, :metadata::jsonb, :created_by)
                     ON CONFLICT (note_id, entity_type, entity_id) DO UPDATE SET label = EXCLUDED.label',
                    [
                        'id' => Uuid::v4(),
                        'note_id' => $noteId,
                        'type' => $mention['entity_type'],
                        'entity_id' => $mention['entity_id'],
                        'label' => $mention['label'],
                        'metadata' => json_encode(['via_document' => 'true']),
                        'created_by' => $identity->userId,
                    ],
                );
            }
        });
    }
}
