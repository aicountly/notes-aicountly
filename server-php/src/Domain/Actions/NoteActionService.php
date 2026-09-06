<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Actions;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Checklist items, and the extra life a checklist item can have.
 *
 * The document stays the source of truth for a checklist's **text** and its
 * **tick**. This table adds what a document node cannot hold — a due date, an
 * assignee, a priority — keyed by the block id, and is reconciled on every save.
 *
 * That direction matters. Typing in the editor must never be blocked on a
 * database write, and a due date must never disappear because someone
 * re-worded the line it hangs off.
 */
final class NoteActionService
{
    /**
     * Reconcile rows against the document's task items.
     *
     * Rows whose block disappeared are soft-deleted rather than removed, so a
     * bad edit followed by an undo does not silently destroy a due date.
     */
    public function syncFromDocument(Identity $identity, string $noteId, array $document): void
    {
        $items = NoteDocument::extractChecklistItems($document);

        Connection::transaction(function () use ($identity, $noteId, $items): void {
            $existing = [];
            foreach (Connection::select(
                'SELECT id, block_id, status, text FROM note_actions
                 WHERE note_id = :note_id AND deleted_at IS NULL AND origin = \'checklist\'',
                ['note_id' => $noteId],
            ) as $row) {
                $existing[(string) $row['block_id']] = $row;
            }

            $seen = [];
            foreach ($items as $item) {
                $seen[$item['block_id']] = true;
                $status = $item['checked'] ? 'done' : 'open';
                $current = $existing[$item['block_id']] ?? null;

                if ($current === null) {
                    Connection::execute(
                        'INSERT INTO note_actions
                            (id, note_id, tenant_id, block_id, text, status, position, origin,
                             completed_at, completed_by, created_by)
                         VALUES
                            (:id, :note_id, :tenant_id, :block_id, :text, :status::text, :position, \'checklist\',
                             CASE WHEN :status::text = \'done\' THEN now() ELSE NULL END,
                             CASE WHEN :status::text = \'done\' THEN :actor::text ELSE NULL END, :actor::text)
                         ON CONFLICT (note_id, block_id) WHERE deleted_at IS NULL AND block_id IS NOT NULL
                         DO UPDATE SET text = EXCLUDED.text, status = EXCLUDED.status, position = EXCLUDED.position',
                        [
                            'id' => Uuid::v4(),
                            'note_id' => $noteId,
                            'tenant_id' => $identity->tenantId,
                            'block_id' => $item['block_id'],
                            'text' => $item['text'],
                            'status' => $status,
                            'position' => $item['position'],
                            'actor' => $identity->userId,
                        ],
                    );
                    continue;
                }

                if ((string) $current['status'] === $status && (string) $current['text'] === $item['text']) {
                    Connection::execute(
                        'UPDATE note_actions SET position = :position WHERE id = :id',
                        ['position' => $item['position'], 'id' => $current['id']],
                    );
                    continue;
                }

                Connection::execute(
                    'UPDATE note_actions
                     SET text = :text, status = :status::text, position = :position, updated_at = now(),
                         completed_at = CASE WHEN :status::text = \'done\' THEN coalesce(completed_at, now()) ELSE NULL END,
                         completed_by = CASE WHEN :status::text = \'done\' THEN coalesce(completed_by, :actor::text) ELSE NULL END
                     WHERE id = :id',
                    [
                        'text' => $item['text'],
                        'status' => $status,
                        'position' => $item['position'],
                        'actor' => $identity->userId,
                        'id' => $current['id'],
                    ],
                );
            }

            foreach ($existing as $blockId => $row) {
                if (!isset($seen[$blockId])) {
                    Connection::execute(
                        'UPDATE note_actions SET deleted_at = now() WHERE id = :id',
                        ['id' => $row['id']],
                    );
                }
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function listForNote(string $noteId): array
    {
        $rows = Connection::select(
            'SELECT * FROM note_actions
             WHERE note_id = :note_id AND deleted_at IS NULL
             ORDER BY position, created_at',
            ['note_id' => $noteId],
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Open actions assigned to, or created by, the caller across every note
     * they can see. Feeds "Suggested by Pulse" and the Reminders screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openForUser(Identity $identity, int $limit = 50): array
    {
        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT act.*, n.title AS note_title, n.note_type
             FROM note_actions act
             JOIN notes n ON n.id = act.note_id AND n.deleted_at IS NULL AND NOT n.is_archived
             JOIN note_access a ON a.note_id = n.id
             WHERE act.deleted_at IS NULL AND act.status = \'open\'
               AND (act.assigned_user_id = :auth_user OR act.assigned_user_id IS NULL)
             ORDER BY act.due_at NULLS LAST, act.updated_at DESC
             LIMIT :limit',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'limit' => $limit,
            ],
        );

        return array_map(function (array $row): array {
            $action = $this->present($row);
            $action['note_title'] = $row['note_title'] === null ? null : (string) $row['note_title'];
            $action['note_type'] = (string) $row['note_type'];

            return $action;
        }, $rows);
    }

    /**
     * Create an action that is not mirrored from a checklist block — a Pulse
     * extraction, or one added straight from the actions panel.
     */
    public function create(Identity $identity, string $noteId, array $input, string $origin = 'manual'): array
    {
        $text = Str::limit(trim((string) ($input['text'] ?? '')), 2000);
        if ($text === '') {
            throw ApiException::validation(['text' => 'An action needs some text.']);
        }

        $id = Uuid::v4();
        Connection::execute(
            'INSERT INTO note_actions
                (id, note_id, tenant_id, text, status, priority, due_at, assigned_user_id, origin, created_by)
             VALUES (:id, :note_id, :tenant_id, :text, \'open\', :priority, :due_at, :assignee, :origin, :actor)',
            [
                'id' => $id,
                'note_id' => $noteId,
                'tenant_id' => $identity->tenantId,
                'text' => $text,
                'priority' => self::priority($input['priority'] ?? null),
                'due_at' => self::timestamp($input['due_at'] ?? null),
                'assignee' => isset($input['assigned_user_id']) ? Str::limit((string) $input['assigned_user_id'], 64) : null,
                'origin' => in_array($origin, ['manual', 'pulse'], true) ? $origin : 'manual',
                'actor' => $identity->userId,
            ],
        );

        return $this->present((array) Connection::selectOne('SELECT * FROM note_actions WHERE id = :id', ['id' => $id]));
    }

    /** @return array<string, mixed> */
    public function update(Identity $identity, string $actionId, array $input): array
    {
        $row = Connection::selectOne(
            'SELECT * FROM note_actions WHERE id = :id AND deleted_at IS NULL',
            ['id' => $actionId],
        );
        if ($row === null) {
            throw ApiException::notFound('That action');
        }

        $updates = [];
        $bindings = ['id' => $actionId];

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['open', 'done', 'cancelled'], true)) {
                throw ApiException::validation(['status' => 'Status must be open, done or cancelled.']);
            }
            // Every use of :status is cast, so Postgres infers one type for the
            // placeholder instead of refusing with "inconsistent types deduced".
            $updates[] = 'status = :status::text';
            $updates[] = 'completed_at = CASE WHEN :status::text = \'done\' THEN now() ELSE NULL END';
            $updates[] = 'completed_by = CASE WHEN :status::text = \'done\' THEN :actor::text ELSE NULL END';
            $bindings['status'] = $status;
            $bindings['actor'] = $identity->userId;
        }
        if (array_key_exists('text', $input)) {
            $updates[] = 'text = :text';
            $bindings['text'] = Str::limit(trim((string) $input['text']), 2000);
        }
        if (array_key_exists('due_at', $input)) {
            $updates[] = 'due_at = :due_at';
            $bindings['due_at'] = self::timestamp($input['due_at']);
        }
        if (array_key_exists('priority', $input)) {
            $updates[] = 'priority = :priority';
            $bindings['priority'] = self::priority($input['priority']);
        }
        if (array_key_exists('assigned_user_id', $input)) {
            $updates[] = 'assigned_user_id = :assignee';
            $bindings['assignee'] = $input['assigned_user_id'] === null
                ? null
                : Str::limit((string) $input['assigned_user_id'], 64);
        }

        if ($updates === []) {
            return $this->present($row);
        }

        $updates[] = 'updated_at = now()';
        Connection::execute('UPDATE note_actions SET ' . implode(', ', $updates) . ' WHERE id = :id', $bindings);

        return $this->present((array) Connection::selectOne('SELECT * FROM note_actions WHERE id = :id', ['id' => $actionId]));
    }

    public function delete(string $actionId): void
    {
        Connection::execute(
            'UPDATE note_actions SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $actionId],
        );
    }

    /** The note an action belongs to — callers authorise against that note, not the action. */
    public function noteIdFor(string $actionId): ?string
    {
        $row = Connection::selectOne(
            'SELECT note_id FROM note_actions WHERE id = :id AND deleted_at IS NULL',
            ['id' => $actionId],
        );

        return $row === null ? null : (string) $row['note_id'];
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'note_id' => (string) $row['note_id'],
            'block_id' => $row['block_id'] === null ? null : (string) $row['block_id'],
            'text' => (string) $row['text'],
            'status' => (string) $row['status'],
            'priority' => $row['priority'] === null ? null : (string) $row['priority'],
            'due_at' => $row['due_at'] === null ? null : (string) $row['due_at'],
            'assigned_user_id' => $row['assigned_user_id'] === null ? null : (string) $row['assigned_user_id'],
            'origin' => (string) $row['origin'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function priority(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $priority = strtolower((string) $value);

        return in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            throw ApiException::validation(['due_at' => 'Use an ISO-8601 date and time.']);
        }
    }
}
