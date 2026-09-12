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

        // Keyset pagination, on whichever column the sort actually orders by.
        //
        // It used to compare `(n.updated_at, n.id) <` for every sort, which is
        // only correct for the default one. `updated_asc` walks the other way,
        // so `<` handed back the page the reader had just read and then jumped
        // past everything in between; `created_*` and `title_*` compared a
        // column the results were not ordered by at all, which repeats some
        // rows and permanently skips others. The keyset has to be built from
        // the same description as the ORDER BY, so here it is.
        $sortName = isset(self::SORTS[(string) ($options['sort'] ?? '')])
            ? (string) $options['sort']
            : 'updated_desc';
        $sortSpec = self::SORTS[$sortName];
        $cursorClause = '';
        $cursor = $this->decodeCursor((string) ($options['cursor'] ?? ''), $sortSpec, $sortName);
        if ($cursor !== null) {
            $comparison = $sortSpec['direction'] === 'asc' ? '>' : '<';
            $cursorClause = sprintf(
                ' AND (%s, n.id) %s (:cursor_value::%s, :cursor_id::uuid)',
                $sortSpec['column'],
                $comparison,
                $sortSpec['cast'],
            );
            $bindings['cursor_value'] = $cursor['value'];
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
        if ($hasMore && $rows !== [] && $sortSpec['column'] !== null) {
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor(
                $sortName,
                self::cursorValue($last, $sortSpec),
                (string) $last['id'],
            );
        }
        // A pinned-first page has no cursor: `is_pinned DESC` is a leading sort
        // key the keyset does not carry, so a cursor built on the second key
        // would silently return wrong pages. `has_more` still says there is
        // more, rather than pretending the list ends here.

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

    /**
     * Every sort, described once.
     *
     * `order` is the ORDER BY; `column`, `direction` and `cast` build the
     * keyset predicate that pages it; `field`/`kind` say how to read the
     * cursor's value back off the last row. Keeping them in one row of one
     * table is the point: they were separate before, and a keyset that
     * disagrees with its ORDER BY does not fail — it quietly returns a page
     * with rows missing.
     *
     * `column` is null where no keyset is possible. `pinned_first` leads with
     * `is_pinned`, a key the two-column cursor cannot carry.
     *
     * @var array<string, array{order: string, column: ?string, direction: string, cast: string, field: string, kind: string}>
     */
    private const SORTS = [
        'updated_desc' => [
            'order' => 'n.updated_at DESC, n.id DESC',
            'column' => 'n.updated_at', 'direction' => 'desc',
            'cast' => 'timestamptz', 'field' => 'updated_at', 'kind' => 'timestamp',
        ],
        'updated_asc' => [
            'order' => 'n.updated_at ASC, n.id ASC',
            'column' => 'n.updated_at', 'direction' => 'asc',
            'cast' => 'timestamptz', 'field' => 'updated_at', 'kind' => 'timestamp',
        ],
        'created_desc' => [
            'order' => 'n.created_at DESC, n.id DESC',
            'column' => 'n.created_at', 'direction' => 'desc',
            'cast' => 'timestamptz', 'field' => 'created_at', 'kind' => 'timestamp',
        ],
        'created_asc' => [
            'order' => 'n.created_at ASC, n.id ASC',
            'column' => 'n.created_at', 'direction' => 'asc',
            'cast' => 'timestamptz', 'field' => 'created_at', 'kind' => 'timestamp',
        ],
        'title_asc' => [
            'order' => "lower(coalesce(n.title, '')) ASC, n.id ASC",
            'column' => "lower(coalesce(n.title, ''))", 'direction' => 'asc',
            'cast' => 'text', 'field' => 'title', 'kind' => 'text',
        ],
        'title_desc' => [
            'order' => "lower(coalesce(n.title, '')) DESC, n.id DESC",
            'column' => "lower(coalesce(n.title, ''))", 'direction' => 'desc',
            'cast' => 'text', 'field' => 'title', 'kind' => 'text',
        ],
        // Pinned notes stay at the top of the default view, which is the point
        // of pinning one.
        'pinned_first' => [
            'order' => 'n.is_pinned DESC, n.updated_at DESC, n.id DESC',
            'column' => null, 'direction' => 'desc',
            'cast' => 'timestamptz', 'field' => 'updated_at', 'kind' => 'timestamp',
        ],
    ];

    private function sortClause(string $sort): string
    {
        $spec = self::SORTS[$sort] ?? self::SORTS['updated_desc'];

        return $spec['order'];
    }

    /**
     * The value the cursor carries for this sort, taken from the last row.
     *
     * It has to equal what the ORDER BY expression produces for that row, or
     * the comparison is against something the results were never sorted by —
     * which is the bug this table exists to prevent. `title` is lowercased
     * here because the SQL lowercases it there.
     *
     * @param array<string, mixed> $row
     * @param array{field: string, kind: string} $spec
     */
    private static function cursorValue(array $row, array $spec): string
    {
        $value = (string) ($row[$spec['field']] ?? '');

        return $spec['kind'] === 'text' ? mb_strtolower($value) : $value;
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
    /** @param array{column: ?string, cast: string, kind: string} $spec */
    private function decodeCursor(string $cursor, array $spec, string $sort): ?array
    {
        if ($cursor === '' || $spec['column'] === null) {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['v'], $decoded['id'], $decoded['s'])) {
            return null;
        }

        $value = is_scalar($decoded['v']) ? (string) $decoded['v'] : '';
        $id = is_scalar($decoded['id']) ? (string) $decoded['id'] : '';

        if (!Uuid::isValid($id)) {
            return null;
        }

        // A cursor is only meaningful under the sort that produced it: the
        // value in it is a title when the list was sorted by title and a
        // timestamp when it was not. Re-sorting mid-list therefore starts
        // again from the top rather than comparing one against the other.
        if ((string) $decoded['s'] !== $sort) {
            return null;
        }

        if ($spec['kind'] === 'timestamp') {
            try {
                $parsed = new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }

            // Re-formatted from the parsed value rather than passed through, so
            // only a canonical timestamp ever reaches the query — and to
            // MICROseconds, because that is what `timestamptz` stores.
            // RFC3339_EXTENDED stops at milliseconds, which rounds the cursor
            // down below the row it was built from: an ascending page then
            // returned its own last row again at the top of the next one, and
            // a descending page silently skipped every other note written in
            // the same millisecond.
            $value = $parsed->format('Y-m-d\TH:i:s.uP');
        } elseif (mb_strlen($value) > 500) {
            // Titles are capped at 500, so anything longer was not made here.
            return null;
        }

        return ['value' => $value, 'id' => strtolower($id)];
    }

    private function encodeCursor(string $sort, string $value, string $id): string
    {
        return rtrim(
            strtr(
                base64_encode((string) json_encode(['s' => $sort, 'v' => $value, 'id' => $id])),
                '+/',
                '-_',
            ),
            '=',
        );
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
                -- Owner-scoped, exactly as the Trash list is. A share grants
                -- access to a note, never a say in whether its owner throws it
                -- away: list() refuses to show a collaborator a trashed note
                -- belonging to someone else, so counting it here put a number
                -- on the badge that the screen behind it then contradicted.
                count(*) FILTER (WHERE n.deleted_at IS NOT NULL
                                   AND n.owner_user_id = :auth_user) AS trashed,
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
