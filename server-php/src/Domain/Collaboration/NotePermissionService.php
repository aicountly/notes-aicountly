<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * The single place this API answers "may they?".
 *
 * Controllers call `requireNote(...)` and get back the note plus the caller's
 * effective role; they never assemble an ownership check of their own. Keeping
 * it in one class is what makes the authorisation rules testable as a unit and
 * reviewable in one sitting, instead of being a property you have to re-verify
 * in every new endpoint.
 *
 * Ambiguity resolves to denial. A note that cannot be read is reported as
 * **404, not 403**, so probing ids cannot be used to learn which notes exist.
 */
final class NotePermissionService
{
    // Capabilities, and the minimum role each needs.
    public const VIEW = 'view';
    public const COMMENT = 'comment';
    public const EDIT = 'edit';
    public const MANAGE = 'manage';

    private const REQUIRED_ROLE = [
        self::VIEW => NoteRole::VIEWER,
        self::COMMENT => NoteRole::COMMENTER,
        self::EDIT => NoteRole::EDITOR,
        // Sharing, deleting, restoring and permanent deletion are the owner's
        // alone: an editor who could re-share a note could widen an audience
        // the owner chose.
        self::MANAGE => NoteRole::OWNER,
    ];

    /**
     * The caller's effective role on a note, or null when they have none.
     *
     * `$includeTrashed` exists for restore and permanent-delete, which act on
     * notes that are soft-deleted and therefore excluded from every other read.
     */
    public function roleFor(Identity $identity, string $noteId, bool $includeTrashed = false): ?string
    {
        $row = $this->fetchWithRole($identity, $noteId, $includeTrashed, columns: 'n.id');

        return $row === null ? null : (string) $row['_role'];
    }

    /**
     * Load a note the caller may act on, or fail.
     *
     * @return array<string, mixed> The note row plus `_role`.
     */
    public function requireNote(
        Identity $identity,
        string $noteId,
        string $capability = self::VIEW,
        bool $includeTrashed = false,
        string $columns = 'n.*',
    ): array {
        $row = $this->fetchWithRole($identity, $noteId, $includeTrashed, $columns);

        if ($row === null) {
            // Deliberately not 403: "you may not see this note" and "there is
            // no such note" must be indistinguishable from outside.
            throw ApiException::notFound('That note');
        }

        $role = (string) $row['_role'];
        if (!NoteRole::atLeast($role, self::REQUIRED_ROLE[$capability])) {
            Logger::warn('authz.denied', [
                'note_id' => $noteId,
                'capability' => $capability,
                'role' => $role,
            ]);
            throw ApiException::forbidden(
                'NOTE_ACCESS_DENIED',
                self::denialMessage($capability, $role),
            );
        }

        // A note whose privacy mode is `private` holds ciphertext the server
        // cannot read. Anyone but the owner would receive bytes they have no
        // key for, so the share surface is closed rather than useless.
        if (($row['privacy_mode'] ?? 'standard') === 'private' && $role !== NoteRole::OWNER) {
            throw ApiException::forbidden(
                'NOTE_PRIVATE',
                'This note is private to its owner and cannot be opened here.',
            );
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWithRole(
        Identity $identity,
        string $noteId,
        bool $includeTrashed,
        string $columns,
    ): ?array {
        $trashClause = $includeTrashed ? '' : 'AND n.deleted_at IS NULL';

        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . '
                SELECT ' . $columns . ', a.role_rank
                FROM notes n
                JOIN note_access a ON a.note_id = n.id
                WHERE n.id = :note_id ' . $trashClause . '
                LIMIT 1';

        $row = Connection::selectOne($sql, [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'note_id' => $noteId,
        ]);

        if ($row === null) {
            return null;
        }

        $row['_role'] = NoteAccess::roleFromRank((int) $row['role_rank']);
        unset($row['role_rank']);

        return $row;
    }

    /**
     * The caller's effective role on a notebook.
     */
    public function notebookRole(Identity $identity, string $notebookId): ?string
    {
        $row = Connection::selectOne(
            'WITH RECURSIVE ' . NoteAccess::notebookCte() . '
             SELECT role_rank FROM notebook_access WHERE notebook_id = :id',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'id' => $notebookId,
            ],
        );

        return $row === null ? null : NoteAccess::roleFromRank((int) $row['role_rank']);
    }

    /** @return array<string, mixed> The notebook row plus `_role`. */
    public function requireNotebook(Identity $identity, string $notebookId, string $capability = self::VIEW): array
    {
        $row = Connection::selectOne(
            'WITH RECURSIVE ' . NoteAccess::notebookCte() . '
             SELECT nb.*, a.role_rank
             FROM notebooks nb
             JOIN notebook_access a ON a.notebook_id = nb.id
             WHERE nb.id = :id AND nb.deleted_at IS NULL',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'id' => $notebookId,
            ],
        );

        if ($row === null) {
            throw ApiException::notFound('That notebook');
        }

        $role = NoteAccess::roleFromRank((int) $row['role_rank']);
        unset($row['role_rank']);

        if (!NoteRole::atLeast($role, self::REQUIRED_ROLE[$capability])) {
            throw ApiException::forbidden('NOTEBOOK_ACCESS_DENIED', self::denialMessage($capability, $role));
        }

        $row['_role'] = $role;

        return $row;
    }

    /**
     * The capability map sent to the client so the UI can disable what the
     * caller cannot do, rather than offer it and fail on click.
     *
     * @return array<string, bool>
     */
    public static function capabilities(string $role): array
    {
        return [
            'view' => NoteRole::atLeast($role, NoteRole::VIEWER),
            'comment' => NoteRole::atLeast($role, NoteRole::COMMENTER),
            'edit' => NoteRole::atLeast($role, NoteRole::EDITOR),
            'share' => NoteRole::atLeast($role, NoteRole::OWNER),
            'delete' => NoteRole::atLeast($role, NoteRole::OWNER),
            'restore' => NoteRole::atLeast($role, NoteRole::OWNER),
            'manage_members' => NoteRole::atLeast($role, NoteRole::OWNER),
        ];
    }

    private static function denialMessage(string $capability, string $role): string
    {
        return match ($capability) {
            self::EDIT => $role === NoteRole::COMMENTER
                ? 'You can comment on this note, but not edit it.'
                : 'You have view-only access to this note.',
            self::COMMENT => 'You do not have permission to comment on this note.',
            self::MANAGE => 'Only the owner can change sharing for this note.',
            default => 'You do not have permission to open this note.',
        };
    }
}
