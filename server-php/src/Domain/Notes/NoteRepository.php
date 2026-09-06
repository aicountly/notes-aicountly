<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Support\Uuid;

/**
 * Reads over the notes table.
 *
 * Two things this class is careful about, because both decide whether the app
 * feels instant with 10,000 notes:
 *
 *   - **Never select `document_json` for a list.** The card only needs a title
 *     and an excerpt; shipping the ProseMirror tree of every note is the
 *     difference between a 40 KB response and a 20 MB one.
 *   - **No N+1.** Tags, attachment counts, reminders and checklist progress for
 *     a whole page are fetched in one query each, keyed by the page's ids.
 */
final class NoteRepository
{
    /** Columns a card needs. Deliberately excludes document_json. */
    private const SUMMARY_COLUMNS = 'n.id, n.note_type, n.title, n.notebook_id, n.color,
        n.is_pinned, n.is_favourite, n.is_archived, n.is_locked, n.privacy_mode,
        n.version, n.word_count, n.char_count, n.owner_user_id,
        n.created_at, n.updated_at, n.deleted_at,
        left(n.extracted_text, 400) AS extracted_text';

    /**
     * One page of notes the caller may see.
     *
     * @param array<string, mixed> $options
     * @return array{notes: array<int, array<string, mixed>>, next_cursor: string|null, total: int|null}
     */
    public function list(Identity $identity, NoteQuery $filters, array $options = []): array
    {
        $limit = max(1, min(100, (int) ($options['limit'] ?? 30)));
        $sort = $this->sortClause((string) ($options['sort'] ?? 'updated_desc'));
        $scope = (string) ($options['scope'] ?? 'active');

        $bindings = [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
        ] + $filters->bindings();

        $where = match ($scope) {
            // Trash is personal. A note someone else deleted is not in *your*
            // trash even when you could read it — you cannot restore it, and
            // listing it would only tell you the owner threw it away.
            'trash' => 'n.deleted_at IS NOT NULL AND n.owner_user_id = :auth_user',
            'archive' => 'n.deleted_at IS NULL AND n.is_archived',
            'all' => 'n.deleted_at IS NULL',
            // The default list hides archived notes: archiving is how a user
            // gets something out of the way, so leaving it in the list would
            // make the action pointless.
            default => 'n.deleted_at IS NULL AND NOT n.is_archived',
        };

        // Shared-with-me means "someone granted this to me", which is exactly
        // "I have access and I am not the owner".
        if (($options['shared_with_me'] ?? false) === true) {
            $where .= ' AND n.owner_user_id <> :auth_user';
        }

        $cursorClause = '';
        $cursor = $this->decodeCursor((string) ($options['cursor'] ?? ''));
        if ($cursor !== null) {
            // Keyset pagination on (updated_at, id): stable when notes are
            // being edited underneath the reader, which OFFSET is not.
            $cursorClause = ' AND (n.updated_at, n.id) < (:cursor_ts::timestamptz, :cursor_id::uuid)';
            $bindings['cursor_ts'] = $cursor['ts'];
            $bindings['cursor_id'] = $cursor['id'];
        }

        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . '
                SELECT ' . self::SUMMARY_COLUMNS . ', a.role_rank
                FROM notes n
                JOIN note_access a ON a.note_id = n.id
                WHERE ' . $where . $cursorClause . $filters->sql() . '
                ORDER BY ' . $sort . '
                LIMIT :limit';

        $bindings['limit'] = $limit + 1;

        $rows = Connection::select($sql, $bindings);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if ($hasMore && $rows !== [] && str_starts_with($sort, 'n.is_pinned') === false) {
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor((string) $last['updated_at'], (string) $last['id']);
        } elseif ($hasMore && $rows !== []) {
            // Pinned-first ordering breaks the (updated_at, id) keyset, so that
            // sort paginates by offset instead of silently returning wrong pages.
            $nextCursor = null;
        }

        return [
            'notes' => $this->hydrate($rows, $identity),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Attach the per-card extras in bulk.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $rows, Identity $identity): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row) => (string) $row['id'], $rows);
        $tags = $this->tagsFor($ids);
        $counts = $this->countsFor($ids);

        $notes = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $row['_role'] = NoteAccess::roleFromRank((int) ($row['role_rank'] ?? 4));
            unset($row['role_rank']);

            $stats = $counts[$id] ?? [];
            $notes[] = NotePresenter::summary($row, [
                'tags' => $tags[$id] ?? [],
                'attachment_count' => (int) ($stats['attachments'] ?? 0),
                'has_reminder' => (bool) ($stats['has_reminder'] ?? false),
                'is_shared' => (bool) ($stats['is_shared'] ?? false)
                    || (string) $row['owner_user_id'] !== $identity->userId,
                'checklist' => isset($stats['checklist_total']) && (int) $stats['checklist_total'] > 0
                    ? [
                        'total' => (int) $stats['checklist_total'],
                        'done' => (int) ($stats['checklist_done'] ?? 0),
                    ]
                    : null,
            ]);
        }

        return $notes;
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function tagsFor(array $ids): array
    {
        $rows = Connection::select(
            'SELECT nt.note_id, t.id, t.name, t.slug, t.color
             FROM note_tags nt JOIN tags t ON t.id = nt.tag_id
             WHERE nt.note_id = ANY(:ids::uuid[])
             ORDER BY t.name',
            ['ids' => '{' . implode(',', $ids) . '}'],
        );

        $byNote = [];
        foreach ($rows as $row) {
            $byNote[(string) $row['note_id']][] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'color' => $row['color'] === null ? null : (string) $row['color'],
            ];
        }

        return $byNote;
    }

    /**
     * Attachment / reminder / checklist counts for a page, in one round trip.
     *
     * @param array<int, string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function countsFor(array $ids): array
    {
        $rows = Connection::select(
            "SELECT n.id AS note_id,
                    (SELECT count(*) FROM note_attachments att
                      WHERE att.note_id = n.id AND att.deleted_at IS NULL) AS attachments,
                    EXISTS (SELECT 1 FROM note_reminders r
                             WHERE r.note_id = n.id AND r.deleted_at IS NULL
                               AND r.status IN ('scheduled','snoozed')) AS has_reminder,
                    EXISTS (SELECT 1 FROM note_members m WHERE m.note_id = n.id) AS is_shared,
                    (SELECT count(*) FROM note_actions a
                      WHERE a.note_id = n.id AND a.deleted_at IS NULL AND a.origin = 'checklist') AS checklist_total,
                    (SELECT count(*) FROM note_actions a
                      WHERE a.note_id = n.id AND a.deleted_at IS NULL AND a.origin = 'checklist'
                        AND a.status = 'done') AS checklist_done
             FROM notes n
             WHERE n.id = ANY(:ids::uuid[])",
            ['ids' => '{' . implode(',', $ids) . '}'],
        );

        $byNote = [];
        foreach ($rows as $row) {
            $byNote[(string) $row['note_id']] = $row;
        }

        return $byNote;
    }

    private function sortClause(string $sort): string
    {
        return match ($sort) {
            'updated_asc' => 'n.updated_at ASC, n.id ASC',
            'created_desc' => 'n.created_at DESC, n.id DESC',
            'created_asc' => 'n.created_at ASC, n.id ASC',
            'title_asc' => 'lower(coalesce(n.title, \'\')) ASC, n.id ASC',
            'title_desc' => 'lower(coalesce(n.title, \'\')) DESC, n.id DESC',
            // Pinned notes stay at the top of the default view, which is the
            // point of pinning one.
            'pinned_first' => 'n.is_pinned DESC, n.updated_at DESC, n.id DESC',
            default => 'n.updated_at DESC, n.id DESC',
        };
    }

    /**
     * @return array{ts: string, id: string}|null Null for anything unusable.
     *
     * A cursor is opaque to the client but arrives from it, so both halves are
     * validated before they reach the query. Binding alone is not enough: the
     * placeholders are cast to `timestamptz` and `uuid`, and Postgres answers a
     * value that is not one of those with an error, not an empty result — so an
     * edited cursor would 500 rather than simply being ignored.
     *
     * An unusable cursor is treated as no cursor: the reader gets the first
     * page instead of an error page.
     */
    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['ts'], $decoded['id'])) {
            return null;
        }

        $timestamp = is_scalar($decoded['ts']) ? (string) $decoded['ts'] : '';
        $id = is_scalar($decoded['id']) ? (string) $decoded['id'] : '';

        if (!Uuid::isValid($id)) {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return null;
        }

        return [
            // Re-formatted from the parsed value rather than passed through, so
            // only a canonical timestamp ever reaches the query.
            'ts' => $parsed->format(\DateTimeInterface::RFC3339_EXTENDED),
            'id' => strtolower($id),
        ];
    }

    private function encodeCursor(string $timestamp, string $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['ts' => $timestamp, 'id' => $id])), '+/', '-_'), '=');
    }

    /**
     * Counts for the sidebar badges, in one query rather than six.
     *
     * @return array<string, int>
     */
    public function sidebarCounts(Identity $identity): array
    {
        $row = Connection::selectOne(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT
                count(*) FILTER (WHERE n.deleted_at IS NULL AND NOT n.is_archived) AS active,
                count(*) FILTER (WHERE n.deleted_at IS NULL AND n.is_archived) AS archived,
                count(*) FILTER (WHERE n.deleted_at IS NOT NULL) AS trashed,
                count(*) FILTER (WHERE n.deleted_at IS NULL AND NOT n.is_archived AND n.is_pinned) AS pinned,
                count(*) FILTER (WHERE n.deleted_at IS NULL AND NOT n.is_archived AND n.is_favourite) AS favourite,
                count(*) FILTER (WHERE n.deleted_at IS NULL AND NOT n.is_archived
                                   AND n.owner_user_id <> :auth_user) AS shared_with_me
             FROM notes n JOIN note_access a ON a.note_id = n.id',
            ['auth_user' => $identity->userId, 'auth_tenant' => $identity->tenantId],
        );

        return array_map('intval', $row ?? []);
    }
}
