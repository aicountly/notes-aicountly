<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Activity;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;

/**
 * Reading the trail {@see ActivityRecorder} writes.
 *
 * The permission check lives in here rather than in the controller because of
 * what the trail contains: who opened a note, who was added to it and when it
 * was shared. A caller that forgot to ask would be handing a note's
 * collaboration history to anyone holding its id, so asking is not optional and
 * therefore not the caller's job.
 *
 * Content never appears. The rows carry ids, roles and counts because that is
 * all `ActivityRecorder` stores, and nothing here re-reads the note to enrich
 * them.
 */
final class ActivityQuery
{
    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
    ) {
    }

    /**
     * One note's activity, newest first.
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int}
     */
    public function forNote(Identity $identity, string $noteId, int $limit, int $offset): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            // privacy_mode comes along so requireNote can still refuse a note
            // that is private to its owner.
            columns: 'n.id, n.privacy_mode',
        );

        $rows = Connection::select(
            // The access CTE is joined even though the check above already
            // passed: it is the gate every read composes, and a query that
            // relies on a caller having checked first is one refactor away from
            // being wrong.
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT act.id, act.actor_user_id, act.action, act.context, act.created_at,
                    count(*) OVER () AS total_count
             FROM note_activity act
             JOIN note_access a ON a.note_id = act.note_id
             WHERE act.note_id = :note_id
             -- Recording collapses a burst into one row by moving its
             -- created_at, so timestamps tie; the id breaks the tie and keeps
             -- paging stable instead of repeating or skipping a row.
             ORDER BY act.created_at DESC, act.id DESC
             LIMIT :limit OFFSET :offset',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'note_id' => $noteId,
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        return [
            'entries' => array_map([self::class, 'present'], $rows),
            // Counted in the same statement as the page, so the total cannot
            // disagree with the rows it describes.
            'total' => (int) ($rows[0]['total_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $context = $row['context'];
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (string) $row['id'],
            'actor_user_id' => (string) $row['actor_user_id'],
            'action' => (string) $row['action'],
            'context' => is_array($context) ? $context : [],
            'created_at' => self::timestamp($row['created_at'] ?? null),
        ];
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
