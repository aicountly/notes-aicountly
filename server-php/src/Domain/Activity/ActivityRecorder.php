<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Activity;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Uuid;

/**
 * The note's audit trail.
 *
 * Deliberately coarse: a row per meaningful act, never per keystroke. Autosave
 * writes revisions, not activity — `note.updated` is recorded once per editing
 * session rather than every four seconds, or the trail becomes unreadable and
 * the table becomes the largest one in the database.
 *
 * `context` carries ids, roles and counts. Never note content: this trail is
 * visible to every collaborator on the note.
 */
final class ActivityRecorder
{
    public const NOTE_CREATED = 'note.created';
    public const NOTE_UPDATED = 'note.updated';
    public const NOTE_ARCHIVED = 'note.archived';
    public const NOTE_UNARCHIVED = 'note.unarchived';
    public const NOTE_TRASHED = 'note.trashed';
    public const NOTE_RESTORED = 'note.restored';
    public const NOTE_DELETED = 'note.deleted';
    public const NOTE_DUPLICATED = 'note.duplicated';
    public const NOTE_MOVED = 'note.moved';
    public const VERSION_RESTORED = 'note.version_restored';
    public const MEMBER_ADDED = 'member.added';
    public const MEMBER_REMOVED = 'member.removed';
    public const MEMBER_ROLE_CHANGED = 'member.role_changed';
    public const COMMENT_ADDED = 'comment.added';
    public const COMMENT_RESOLVED = 'comment.resolved';
    public const ATTACHMENT_ADDED = 'attachment.added';
    public const ATTACHMENT_REMOVED = 'attachment.removed';
    public const REMINDER_SET = 'reminder.set';
    public const REMINDER_CLEARED = 'reminder.cleared';

    /** Actions worth one row even when they repeat within the window. */
    private const ALWAYS_RECORD = [
        self::MEMBER_ADDED, self::MEMBER_REMOVED, self::MEMBER_ROLE_CHANGED,
        self::NOTE_DELETED, self::NOTE_RESTORED, self::VERSION_RESTORED,
    ];

    /** @param array<string, mixed> $context */
    public function record(
        Identity $identity,
        string $action,
        ?string $noteId = null,
        ?string $notebookId = null,
        array $context = [],
    ): void {
        try {
            // Collapse a burst of the same act on the same note by the same
            // person — a five-minute editing session is one `note.updated`.
            if (!in_array($action, self::ALWAYS_RECORD, true) && $noteId !== null) {
                $recent = Connection::selectOne(
                    'SELECT id FROM note_activity
                     WHERE note_id = :note_id AND actor_user_id = :actor AND action = :action
                       AND created_at > now() - interval \'5 minutes\'
                     LIMIT 1',
                    ['note_id' => $noteId, 'actor' => $identity->userId, 'action' => $action],
                );
                if ($recent !== null) {
                    Connection::execute(
                        'UPDATE note_activity SET created_at = now() WHERE id = :id',
                        ['id' => $recent['id']],
                    );

                    return;
                }
            }

            Connection::execute(
                'INSERT INTO note_activity (id, note_id, notebook_id, actor_user_id, action, context)
                 VALUES (:id, :note_id, :notebook_id, :actor, :action, :context::jsonb)',
                [
                    'id' => Uuid::v4(),
                    'note_id' => $noteId,
                    'notebook_id' => $notebookId,
                    'actor' => $identity->userId,
                    'action' => $action,
                    'context' => json_encode(Logger::scrub($context)),
                ],
            );
        } catch (\Throwable $e) {
            // The trail is valuable, but not more valuable than the write that
            // produced it: a failure here must not roll back a saved note.
            Logger::warn('activity.record_failed', ['action' => $action, 'error' => get_debug_type($e)]);
        }
    }
}
