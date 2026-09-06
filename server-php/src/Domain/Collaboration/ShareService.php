<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\RateLimiter;
use Aicountly\Api\Support\Uuid;

/**
 * Who a note is shared with.
 *
 * Sharing is the act with the largest blast radius in a notes product: it is
 * the only one that makes private writing readable by someone else, and it
 * cannot be undone for anything already read. So the rules here are narrow on
 * purpose.
 *
 *   - **Only the owner shares.** {@see NotePermissionService::MANAGE} resolves
 *     to `owner`, so an editor cannot widen an audience the owner chose. Every
 *     write below asks for MANAGE first and nothing here re-derives that answer.
 *   - **`owner` is not grantable.** {@see NoteRole::GRANTABLE} exists for this:
 *     sharing widens who can read, it never hands the note over. A transfer of
 *     ownership would be a different endpoint with a different confirmation.
 *   - **Revocation is immediate.** Nothing caches an effective role: the
 *     session cache in {@see \Aicountly\Api\Auth\SessionGuard} remembers *who
 *     the caller is*, never *what they may open*, and permissions are resolved
 *     from `note_members` on every request. Deleting a row here is therefore
 *     the whole of "remove their access", and it applies to the very next call.
 */
final class ShareService
{
    /** Portal ids are opaque to this service; the column is varchar(64). */
    private const MAX_USER_ID = 64;

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
    ) {
    }

    /**
     * Who this note is shared with, owner included.
     *
     * Readable by anyone who can open the note, not only by the owner: a
     * collaborator who cannot see who else is in a note has no way to judge
     * what is safe to write in it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(Identity $identity, string $noteId): array
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            // privacy_mode travels with every fetch so requireNote can still
            // refuse a note whose contents the server cannot read.
            columns: 'n.id, n.privacy_mode, n.owner_user_id, n.created_at, n.updated_at',
        );

        $rows = Connection::select(
            'SELECT user_id, role, invited_by, created_at, updated_at
             FROM note_members WHERE note_id = :note_id
             ORDER BY created_at, user_id',
            ['note_id' => $noteId],
        );

        // The owner is not a row in note_members — ownership comes from the
        // note itself — but a sharing dialog that omitted them would read as
        // "shared with nobody" on a note you own.
        $members = [self::present([
            'user_id' => (string) $note['owner_user_id'],
            'role' => NoteRole::OWNER,
            'invited_by' => null,
            'created_at' => $note['created_at'] ?? null,
            'updated_at' => $note['updated_at'] ?? null,
        ])];

        foreach ($rows as $row) {
            $members[] = self::present($row);
        }

        return $members;
    }

    /**
     * Grant access, or change the access someone already has.
     *
     * POST doubles as "change this person's role" because that is what a
     * sharing dialog does when the same name is picked twice; the caller is
     * told which happened so the HTTP status can be honest about it.
     *
     * @param array<string, mixed> $input
     * @return array{created: bool, member: array<string, mixed>}
     */
    public function addMember(Identity $identity, string $noteId, array $input): array
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::MANAGE,
            columns: 'n.id, n.privacy_mode, n.owner_user_id',
        );

        // Limited after the permission check, so a stranger probing note ids
        // cannot spend the budget of the owner they are probing. Sharing is
        // limited at all because a grant is a real, immediate widening of who
        // can read somebody's writing, and the act is cheap to script: without
        // a bucket, one stolen session could hand a note to every id it can
        // guess before anyone notices. (It is not limited because anything is
        // emailed — this deployment has no notification service; see
        // ReminderService, which says the same.)
        RateLimiter::hit('share', $identity->userId);

        $userId = self::memberUserId($input['user_id'] ?? null);
        $role = $this->grantableRole($input['role'] ?? NoteRole::VIEWER);

        $this->refuseSelfShare($identity, $note, $userId);

        // A `private` note holds ciphertext this server cannot read, and
        // NotePermissionService refuses everyone but the owner on it. Storing
        // the grant anyway would put a name in the sharing dialog that opens
        // nothing — access that exists on screen and not in fact.
        if (($note['privacy_mode'] ?? 'standard') === 'private') {
            throw ApiException::forbidden(
                'NOTE_PRIVATE',
                'A private note is readable only by its owner and cannot be shared.',
            );
        }

        // Matched the way every other path here matches a member: case
        // insensitively. Removal and role changes already do (the front
        // controller lower-cases the request path, so a mixed-case portal id
        // never survives the trip through a URL), and matching *exactly* only
        // here is what let one person end up holding two grants — the second
        // spelling inserted a row beside the first instead of updating it,
        // because the unique constraint is on the stored bytes. The owner then
        // read "changed to viewer" while the editor grant they thought they had
        // narrowed was still in force.
        $existing = $this->findMember($noteId, $userId);

        // Write against the spelling already stored, so the upsert's conflict
        // target (note_id, user_id) lands on that row rather than beside it.
        $memberId = $existing === null ? $userId : (string) $existing['user_id'];

        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:row_id, :note_id, :user, :role, :actor)
             ON CONFLICT (note_id, user_id)
             DO UPDATE SET role = excluded.role, updated_at = now()',
            [
                'row_id' => Uuid::v4(),
                'note_id' => $noteId,
                'user' => $memberId,
                'role' => $role,
                'actor' => $identity->userId,
            ],
        );

        $previous = $existing === null ? null : (string) $existing['role'];
        $this->activity->record(
            $identity,
            $existing === null ? ActivityRecorder::MEMBER_ADDED : ActivityRecorder::MEMBER_ROLE_CHANGED,
            $noteId,
            null,
            ['member_user_id' => $memberId, 'role' => $role, 'previous_role' => $previous],
        );

        return [
            'created' => $existing === null,
            'member' => $this->requireMember($noteId, $memberId),
        ];
    }

    /**
     * Change one member's role.
     *
     * PATCH exists alongside POST because a role picker in a member list is
     * editing a member that already exists; a POST that silently created one
     * would turn a mis-typed id into a new grant instead of a 404.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateMember(Identity $identity, string $noteId, string $userId, array $input): array
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::MANAGE,
            columns: 'n.id, n.privacy_mode, n.owner_user_id',
        );

        RateLimiter::hit('share', $identity->userId);

        $userId = self::memberUserId($userId);
        $role = $this->grantableRole($input['role'] ?? null);

        // The owner has no membership row, so this would otherwise fail as a
        // missing member — which reads as "there is no owner" rather than as
        // "the owner's role is not something sharing can change".
        if (strcasecmp($userId, (string) $note['owner_user_id']) === 0) {
            throw ApiException::badRequest('The owner cannot be given a different role on their own note.');
        }

        $existing = $this->findMember($noteId, $userId);
        if ($existing === null) {
            throw ApiException::notFound('That member');
        }

        Connection::execute(
            'UPDATE note_members SET role = :role, updated_at = now()
             WHERE note_id = :note_id AND user_id = :user',
            ['role' => $role, 'note_id' => $noteId, 'user' => (string) $existing['user_id']],
        );

        $this->activity->record($identity, ActivityRecorder::MEMBER_ROLE_CHANGED, $noteId, null, [
            'member_user_id' => (string) $existing['user_id'],
            'role' => $role,
            'previous_role' => (string) $existing['role'],
        ]);

        return $this->requireMember($noteId, (string) $existing['user_id']);
    }

    /**
     * Revoke someone's access.
     *
     * Deliberately not rate limited: taking access away is the recovery from
     * having shared something by mistake, and an owner who was refused that
     * because they had been busy sharing is left watching a note they cannot
     * close.
     */
    public function removeMember(Identity $identity, string $noteId, string $userId): void
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::MANAGE,
            columns: 'n.id, n.privacy_mode, n.owner_user_id',
        );

        $userId = self::memberUserId($userId);

        if (strcasecmp($userId, $identity->userId) === 0) {
            throw ApiException::badRequest('You cannot remove your own access to a note you manage.');
        }
        // A note with no owner is a note nobody can share, restore or delete;
        // the grant is structural, not a membership row to be tidied away.
        if (strcasecmp($userId, (string) $note['owner_user_id']) === 0) {
            throw ApiException::badRequest('The owner cannot be removed from their own note.');
        }

        // Matched case-insensitively because the front controller lower-cases
        // the whole request path, so a mixed-case portal id never survives the
        // trip through the URL.
        $removed = Connection::execute(
            'DELETE FROM note_members WHERE note_id = :note_id AND lower(user_id) = lower(:user)',
            ['note_id' => $noteId, 'user' => $userId],
        );

        if ($removed === 0) {
            throw ApiException::notFound('That member');
        }

        $this->activity->record($identity, ActivityRecorder::MEMBER_REMOVED, $noteId, null, [
            'member_user_id' => $userId,
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * Refuse the two grants that are already true.
     *
     * Both are stated separately even though the caller holding MANAGE is
     * usually the owner: MANAGE can also arrive through an `owner` role on a
     * notebook this note sits in, and then "me" and "the owner" are two
     * different people with two different reasons to be refused.
     *
     * @param array<string, mixed> $note
     */
    private function refuseSelfShare(Identity $identity, array $note, string $userId): void
    {
        if (strcasecmp($userId, $identity->userId) === 0) {
            throw ApiException::badRequest('You already have access to this note.');
        }
        if (strcasecmp($userId, (string) $note['owner_user_id']) === 0) {
            throw ApiException::badRequest('The owner already has full access to this note.');
        }
    }

    private function grantableRole(mixed $role): string
    {
        // `owner` is absent from GRANTABLE on purpose, so this rejects it the
        // same way it rejects a typo rather than needing a case of its own.
        if (!NoteRole::isGrantable($role)) {
            throw ApiException::validation([
                'role' => 'Choose one of: ' . implode(', ', NoteRole::GRANTABLE) . '.',
            ]);
        }

        return (string) $role;
    }

    private static function memberUserId(mixed $value): string
    {
        // A portal id is opaque text; an integer is taken because a JSON number
        // is a plausible way to send one. A boolean is not an id at all — cast
        // through (string), `true` becomes "1", which would file the grant
        // against whoever happens to be user "1".
        $userId = is_string($value) || is_int($value) ? (string) $value : '';

        // Control characters are stripped rather than trimmed away, because of
        // what one of them does further down: PDO hands the value to libpq as a
        // C string, so `"user-b\0not-bob"` is stored as `user-b` — a grant to
        // somebody other than the id the caller sent, with nothing to show for
        // it. See CommentService::clean(), which exists for the same reason.
        $userId = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $userId));

        if ($userId === '' || mb_strlen($userId, 'UTF-8') > self::MAX_USER_ID) {
            throw ApiException::validation(['user_id' => 'Choose someone to share this note with.']);
        }

        return $userId;
    }

    /** @return array<string, mixed>|null */
    private function findMember(string $noteId, string $userId): ?array
    {
        return Connection::selectOne(
            'SELECT user_id, role, invited_by, created_at, updated_at
             FROM note_members WHERE note_id = :note_id AND lower(user_id) = lower(:user)',
            ['note_id' => $noteId, 'user' => $userId],
        );
    }

    /** @return array<string, mixed> */
    private function requireMember(string $noteId, string $userId): array
    {
        $row = $this->findMember($noteId, $userId);
        if ($row === null) {
            throw ApiException::notFound('That member');
        }

        return self::present($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $role = (string) $row['role'];

        return [
            'user_id' => (string) $row['user_id'],
            'role' => $role,
            'invited_by' => $row['invited_by'] === null ? null : (string) $row['invited_by'],
            // The same idea as NotePermissionService::capabilities(): the UI
            // disables what this member cannot do instead of showing them a
            // control that fails on click.
            'capabilities' => NotePermissionService::capabilities($role),
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
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
