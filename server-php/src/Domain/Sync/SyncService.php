<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Sync;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Actions\NoteActionService;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Notes\NoteRepository;
use Aicountly\Api\Domain\Notes\NotesService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Draining a client's offline queue, and handing it back what changed.
 *
 * The whole design follows from one fact: the client sends the same batch more
 * than once. A reconnect that half-succeeds, a tab that wakes twice, a retry
 * after a 502 — all of them replay operations the server may already have
 * applied. So every operation carries an `operation_id` the device generated,
 * and `sync_operations` is the ledger that makes replay a no-op: an id already
 * in it replays its recorded answer instead of applying anything.
 *
 * Three rules the rest of this class exists to keep:
 *
 *   - **One bad operation does not fail the batch.** Every operation gets its
 *     own status. A note deleted on another device, a validation failure or a
 *     conflict is reported against that operation and the other nine still
 *     save. An all-or-nothing push would mean one poisoned entry blocks a
 *     queue forever.
 *   - **A conflict never overwrites.** {@see NotesService} already answers a
 *     stale version with `VERSION_CONFLICT` and the server's current note; here
 *     that is caught per operation and reported as `conflict`, so the user gets
 *     the choice. Picking a winner silently is how an afternoon's writing
 *     disappears without anyone noticing.
 *   - **Nothing here decides who may do what.** Every operation goes through
 *     the same service the online endpoint calls, so the permission and tenancy
 *     rules are the ones already reviewed — an offline edit to a note someone
 *     un-shared this morning is refused exactly as it would be online.
 */
final class SyncService
{
    /**
     * Operations accepted in one push.
     *
     * The client coalesces updates and drains often, so a queue this long means
     * something is wrong on the device rather than that the user was on a long
     * flight. Refusing the request is better than spending a minute applying it.
     */
    public const MAX_BATCH = 100;

    /** Notes per pull page, and the ceiling a client may ask for. */
    public const PULL_DEFAULT = 100;
    public const PULL_MAX = 200;

    /** Deleted ids are eight bytes each, so a page of them can be generous. */
    private const DELETED_CAP = 500;

    /** The zero UUID, so a plain `?since=<iso>` sorts before every real id. */
    private const MIN_UUID = '00000000-0000-0000-0000-000000000000';
    private const EPOCH = '1970-01-01T00:00:00.000000+00:00';

    /**
     * Microseconds, not milliseconds.
     *
     * `RFC3339_EXTENDED` stops at three decimal places, and Postgres keeps six.
     * A cursor rounded down by a few microseconds sits *before* the row it was
     * taken from, so that row comes back on every page — which is a client
     * re-downloading its own last note forever, and a page cap that never
     * clears.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:s.uP';

    /**
     * The same column list `GET /notes` uses, and for the same reason: a sync
     * page carries what a card needs, never `document_json`. A client that
     * learns a note's version moved can fetch that one document; a pull that
     * shipped 200 ProseMirror trees would be the slowest call in the product.
     */
    private const SUMMARY_COLUMNS = 'n.id, n.note_type, n.title, n.notebook_id, n.color,
        n.is_pinned, n.is_favourite, n.is_archived, n.is_locked, n.privacy_mode,
        n.version, n.word_count, n.char_count, n.owner_user_id,
        n.created_at, n.updated_at, n.deleted_at,
        left(n.extracted_text, 400) AS extracted_text';

    /** The metadata toggles, as the field each one sets. */
    private const NOTE_FLAGS = [
        'note.archive' => ['is_archived', true],
        'note.unarchive' => ['is_archived', false],
        'note.pin' => ['is_pinned', true],
        'note.unpin' => ['is_pinned', false],
        'note.favourite' => ['is_favourite', true],
        'note.unfavourite' => ['is_favourite', false],
    ];

    public function __construct(
        private readonly NotesService $notes = new NotesService(),
        private readonly NoteActionService $actions = new NoteActionService(),
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly NoteRepository $repository = new NoteRepository(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Push
    // -----------------------------------------------------------------------

    /**
     * Apply a queue, operation by operation.
     *
     * @param array<int, mixed> $operations
     * @return array{results: array<int, array<string, mixed>>, applied: int, conflicts: int, rejected: int}
     */
    public function push(Identity $identity, array $operations): array
    {
        if (count($operations) > self::MAX_BATCH) {
            throw ApiException::badRequest(
                sprintf('Send at most %d operations per push.', self::MAX_BATCH),
            );
        }

        $results = [];
        foreach ($operations as $operation) {
            // Each operation is its own unit of work. Wrapping the batch in one
            // transaction would make the ninth failure undo the first eight,
            // which is precisely the behaviour a per-operation status exists to
            // avoid.
            $results[] = $this->applyOne($identity, is_array($operation) ? $operation : []);
        }

        return [
            'results' => $results,
            'applied' => self::countStatus($results, 'applied'),
            'conflicts' => self::countStatus($results, 'conflict'),
            'rejected' => self::countStatus($results, 'rejected'),
        ];
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyOne(Identity $identity, array $operation): array
    {
        // Bounded here, once, so nothing downstream has to think about it: the
        // ledger column is varchar(30) and the name is echoed back in a result.
        $name = Str::limit(
            strtolower(trim((string) (is_scalar($operation['operation'] ?? null) ? $operation['operation'] : ''))),
            30,
        );
        $entityId = Uuid::isValid($operation['entity_id'] ?? null)
            ? strtolower((string) $operation['entity_id'])
            : null;
        // The entity type follows from the operation rather than from what the
        // client claimed, so a mislabelled entry cannot make an action id be
        // treated as a note id further down.
        $entityType = str_starts_with($name, 'action.') ? 'action' : 'note';

        if (!Uuid::isValid($operation['operation_id'] ?? null)) {
            // Without an id there is nothing to record, so this one can never
            // become idempotent — it is refused rather than applied blind.
            return self::entry('', $entityType, $entityId, $name, 'rejected', [
                'error' => [
                    'code' => 'OPERATION_ID_INVALID',
                    'message' => 'Every queued operation needs a UUID operation_id.',
                ],
            ]);
        }

        $operationId = strtolower((string) $operation['operation_id']);
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $recorded = $this->recorded($operationId);
        if ($recorded !== null) {
            return $this->replay($identity, $recorded);
        }

        try {
            [$status, $result] = $this->dispatch($identity, $name, $entityId, $payload);
        } catch (ApiException $e) {
            // A stale version is the one failure the user has to answer, so it
            // keeps its own status and carries the server's note back verbatim.
            $isConflict = $e->errorCode === 'VERSION_CONFLICT';
            $status = $isConflict ? 'conflict' : 'rejected';
            $result = $isConflict
                ? $e->details
                : ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + self::fields($e)];
        } catch (\Throwable $e) {
            // The class, never the note: a failed sync must not put a document
            // into the log file.
            Logger::error('sync.operation_failed', ['operation' => $name, 'error' => get_debug_type($e)]);
            $status = 'rejected';
            $result = ['error' => [
                'code' => 'SYNC_OPERATION_FAILED',
                'message' => 'That change could not be applied.',
            ]];
        }

        return $this->record(
            $identity,
            $operationId,
            $entityType,
            $entityId,
            $name,
            $status,
            $result,
            self::timestamp($operation['client_stamp'] ?? null),
        );
    }

    /**
     * Route one operation to the service that owns its rules.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function dispatch(Identity $identity, string $operation, ?string $entityId, array $payload): array
    {
        if (isset(self::NOTE_FLAGS[$operation])) {
            [$field, $value] = self::NOTE_FLAGS[$operation];

            return ['applied', ['note' => $this->notes->update($identity, self::entity($entityId), [$field => $value])]];
        }

        return match ($operation) {
            // The id the device used offline is the id the note keeps, which is
            // what lets a replayed create find its own note instead of making a
            // second one.
            'note.create' => ['applied', ['note' => $this->notes->create($identity, ['id' => self::entity($entityId)] + $payload)]],
            'note.update' => ['applied', ['note' => $this->notes->update($identity, self::entity($entityId), $payload)]],
            'note.trash' => ['applied', $this->trash($identity, self::entity($entityId))],
            'note.restore' => ['applied', ['note' => $this->notes->restore($identity, self::entity($entityId))]],
            'action.complete' => ['applied', ['action' => $this->completeAction($identity, self::entity($entityId), $payload)]],
            // Permanent deletion is deliberately not in this vocabulary. A
            // destruction taken offline and replayed ten minutes later is not
            // one the user can take back, so it only happens online, in front
            // of the person doing it.
            default => throw ApiException::badRequest(
                sprintf('`%s` is not an operation this API replays.', $operation),
            ),
        };
    }

    /** @return array<string, mixed> */
    private function trash(Identity $identity, string $noteId): array
    {
        $this->notes->trash($identity, $noteId);

        return ['trashed' => true];
    }

    /**
     * Tick a checklist item that was ticked offline.
     *
     * The action id carries no note in it, so the note it belongs to is
     * resolved first and the ordinary EDIT check applied to *that* — otherwise
     * the action id would itself be the authorisation.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function completeAction(Identity $identity, string $actionId, array $payload): array
    {
        $noteId = $this->actions->noteIdFor($actionId);
        if ($noteId === null) {
            throw ApiException::notFound('That action');
        }

        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: 'n.id, n.privacy_mode',
        );

        $status = is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : 'done';

        return $this->actions->update($identity, $actionId, ['status' => $status]);
    }

    // -----------------------------------------------------------------------
    // The idempotency ledger
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function recorded(string $operationId): ?array
    {
        return Connection::selectOne(
            'SELECT operation_id, user_id, entity_type, entity_id, operation, status, result
             FROM sync_operations WHERE operation_id = :id',
            ['id' => $operationId],
        );
    }

    /**
     * Record the outcome, or converge on one that beat us to it.
     *
     * Two tabs draining the same queue can both miss the lookup above and both
     * apply. `ON CONFLICT DO NOTHING` decides which answer is the answer, and
     * the loser reads it back rather than reporting its own — so the device
     * sees one result per operation id however many times it was sent.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function record(
        Identity $identity,
        string $operationId,
        string $entityType,
        ?string $entityId,
        string $operation,
        string $status,
        array $result,
        ?string $clientStamp,
    ): array {
        $written = Connection::execute(
            'INSERT INTO sync_operations
                (operation_id, user_id, entity_type, entity_id, operation, status, result, client_stamp)
             VALUES (:id, :user_id, :entity_type, :entity_id, :operation, :status, :result::jsonb, :client_stamp)
             ON CONFLICT (operation_id) DO NOTHING',
            [
                'id' => $operationId,
                'user_id' => $identity->userId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'operation' => $operation,
                'status' => $status,
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES),
                'client_stamp' => $clientStamp,
            ],
        );

        if ($written === 0) {
            $existing = $this->recorded($operationId);
            if ($existing !== null) {
                return $this->replay($identity, $existing);
            }
        }

        return self::entry($operationId, $entityType, $entityId, $operation, $status, $result);
    }

    /**
     * Hand back what this operation id already answered.
     *
     * The stored answer is a snapshot, and that is the point: a replay must say
     * what the operation did, not what the note looks like now — the client
     * learns the current state from `GET /sync/pull`, which is ordered and
     * paged for exactly that.
     *
     * Access is re-checked all the same. An operation applied last week to a
     * note the caller has since been removed from must not be a way to read
     * that note today.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(Identity $identity, array $row): array
    {
        $operationId = (string) $row['operation_id'];
        $entityType = (string) $row['entity_type'];
        $entityId = $row['entity_id'] === null ? null : (string) $row['entity_id'];
        $operation = (string) $row['operation'];

        if ((string) $row['user_id'] !== $identity->userId) {
            // Operation ids are minted on the device, so two users colliding on
            // one is possible however unlikely. Replaying the other user's
            // answer would hand over their note, so the collision is refused.
            return self::entry($operationId, $entityType, $entityId, $operation, 'rejected', [
                'error' => [
                    'code' => 'OPERATION_ID_TAKEN',
                    'message' => 'That operation id belongs to another device. Send it again with a new id.',
                ],
            ]);
        }

        $decoded = is_string($row['result']) ? json_decode((string) $row['result'], true) : $row['result'];
        $result = is_array($decoded) ? $decoded : [];

        if ($entityType === 'note'
            && $entityId !== null
            && isset($result['note'])
            && $this->permissions->roleFor($identity, $entityId, includeTrashed: true) === null) {
            unset($result['note']);
            $result['note_unavailable'] = true;
        }

        return self::entry($operationId, $entityType, $entityId, $operation, (string) $row['status'], $result, true);
    }

    /**
     * Drop ledger rows older than the retention window.
     *
     * The ledger only has to outlive a client's queue; a row nobody can still
     * be replaying is dead weight on a table every push reads. Meant for the
     * background worker — see `Worker::maintenance()`.
     */
    public function purgeExpiredOperations(int $days = 30): int
    {
        return Connection::execute(
            'DELETE FROM sync_operations WHERE created_at < now() - make_interval(days => :days)',
            ['days' => max(1, min(365, $days))],
        );
    }

    // -----------------------------------------------------------------------
    // Pull
    // -----------------------------------------------------------------------

    /**
     * What changed since the client last looked.
     *
     * Two streams, because a note leaves the list two different ways. Edits are
     * ordered by `updated_at`; trashing does not touch `updated_at`, so deleted
     * ids are tracked separately by `deleted_at`. The cursor carries a
     * watermark for each, and each only advances over rows this page actually
     * returned — a stream that reports nothing keeps its place rather than
     * jumping to "now" and stepping over a write that landed mid-request.
     *
     * @return array{
     *     notes: array<int, array<string, mixed>>,
     *     deleted_note_ids: array<int, string>,
     *     cursor: string,
     *     has_more: bool
     * }
     */
    public function pull(Identity $identity, string $since, int $limit): array
    {
        $limit = max(1, min(self::PULL_MAX, $limit));
        $cursor = self::decodeCursor($since);

        $scope = ['auth_user' => $identity->userId, 'auth_tenant' => $identity->tenantId];

        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT ' . self::SUMMARY_COLUMNS . ', a.role_rank
             FROM notes n
             JOIN note_access a ON a.note_id = n.id
             WHERE n.deleted_at IS NULL
               AND (n.updated_at, n.id) > (:cursor_ts::timestamptz, :cursor_id::uuid)
             ORDER BY n.updated_at ASC, n.id ASC
             LIMIT :limit',
            $scope + [
                'cursor_ts' => $cursor['ts'],
                'cursor_id' => $cursor['id'],
                'limit' => $limit + 1,
            ],
        );

        $hasMoreNotes = count($rows) > $limit;
        if ($hasMoreNotes) {
            array_pop($rows);
        }

        $deletedRows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT n.id, n.deleted_at
             FROM notes n
             JOIN note_access a ON a.note_id = n.id
             WHERE n.deleted_at IS NOT NULL AND n.deleted_at > :deleted_ts::timestamptz
             ORDER BY n.deleted_at ASC, n.id ASC
             LIMIT :limit',
            $scope + ['deleted_ts' => $cursor['del'], 'limit' => self::DELETED_CAP + 1],
        );

        $hasMoreDeleted = count($deletedRows) > self::DELETED_CAP;
        if ($hasMoreDeleted) {
            array_pop($deletedRows);
        }

        $next = $cursor;
        if ($rows !== []) {
            $last = $rows[count($rows) - 1];
            $next['ts'] = self::iso((string) $last['updated_at']);
            $next['id'] = (string) $last['id'];
        }
        if ($deletedRows !== []) {
            $next['del'] = self::iso((string) $deletedRows[count($deletedRows) - 1]['deleted_at']);
        }

        return [
            'notes' => $this->repository->hydrate($rows, $identity),
            'deleted_note_ids' => array_map(static fn (array $row): string => (string) $row['id'], $deletedRows),
            'cursor' => self::encodeCursor($next),
            'has_more' => $hasMoreNotes || $hasMoreDeleted,
        ];
    }

    // -----------------------------------------------------------------------
    // Cursors
    // -----------------------------------------------------------------------

    /**
     * Read `?since=`, in either shape a client can send.
     *
     * A first sync sends nothing. A simple client sends an ISO timestamp. A
     * client that has synced before sends back the opaque cursor, which also
     * carries the id half of the keyset so two notes saved in the same
     * microsecond cannot straddle a page boundary and lose one.
     *
     * Anything unparseable is treated as a full sync rather than as an error:
     * a client with a corrupted cursor must be able to recover by itself.
     *
     * @return array{ts: string, id: string, del: string}
     */
    private static function decodeCursor(string $since): array
    {
        $trimmed = trim($since);
        if ($trimmed === '') {
            return ['ts' => self::EPOCH, 'id' => self::MIN_UUID, 'del' => self::EPOCH];
        }

        $raw = base64_decode(strtr($trimmed, '-_', '+/'), true);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['ts'])) {
            $ts = self::parse(is_scalar($decoded['ts']) ? (string) $decoded['ts'] : '');
            $del = self::parse(is_scalar($decoded['del'] ?? null) ? (string) $decoded['del'] : '');
            $id = is_scalar($decoded['id'] ?? null) ? strtolower((string) $decoded['id']) : '';

            if ($ts !== null) {
                return [
                    'ts' => $ts,
                    // Re-validated rather than passed through: the placeholder
                    // is cast to `uuid`, and Postgres answers a value that is
                    // not one with an error rather than an empty page.
                    'id' => Uuid::isValid($id) ? $id : self::MIN_UUID,
                    'del' => $del ?? $ts,
                ];
            }
        }

        $timestamp = self::parse($trimmed);
        if ($timestamp === null) {
            return ['ts' => self::EPOCH, 'id' => self::MIN_UUID, 'del' => self::EPOCH];
        }

        // A bare timestamp has no id half, so the page starts before every id
        // at that instant. A note saved exactly on the boundary is sent again
        // rather than skipped — the client already handles a note it has.
        return ['ts' => $timestamp, 'id' => self::MIN_UUID, 'del' => $timestamp];
    }

    /** @param array{ts: string, id: string, del: string} $cursor */
    private static function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($cursor)), '+/', '-_'), '=');
    }

    private static function parse(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(self::TIMESTAMP_FORMAT);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function iso(string $value): string
    {
        return self::parse($value) ?? self::EPOCH;
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_scalar($value) ? self::parse((string) $value) : null;
    }

    // -----------------------------------------------------------------------

    private static function entity(?string $entityId): string
    {
        if ($entityId === null) {
            throw ApiException::badRequest('That operation needs an entity_id.');
        }

        return $entityId;
    }

    /**
     * The field errors from a validation failure, which are about the shape of
     * the payload and carry no note content.
     *
     * @return array<string, mixed>
     */
    private static function fields(ApiException $e): array
    {
        return isset($e->details['fields']) && is_array($e->details['fields'])
            ? ['fields' => $e->details['fields']]
            : [];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function entry(
        string $operationId,
        string $entityType,
        ?string $entityId,
        string $operation,
        string $status,
        array $result,
        bool $replayed = false,
    ): array {
        // The envelope is built first so a stored result can never overwrite
        // the status or the ids it is filed under.
        return [
            'operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'status' => $status,
            'replayed' => $replayed,
        ] + $result;
    }

    /** @param array<int, array<string, mixed>> $results */
    private static function countStatus(array $results, string $status): int
    {
        return count(array_filter($results, static fn (array $r): bool => $r['status'] === $status));
    }
}
