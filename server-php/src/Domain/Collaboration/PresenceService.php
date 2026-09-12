<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Features;
use Aicountly\Api\Http\RateLimiter;

/**
 * Who else has a note open, and whether it has moved on since you last saw it.
 *
 * This is "live collaboration" as this deployment target can actually offer
 * it. There is no persistent connection: cPanel has no daemon to hold a
 * WebSocket or an SSE stream open, and the sibling product that already
 * looked at this problem — Pulse's `useNotifications.js` — reached the same
 * conclusion for the same reason and settled on the same answer. So the
 * client polls this endpoint every few seconds while a note is open, and
 * "arrives as it happens" means arrives within one poll interval rather than
 * within a video frame.
 *
 * Two questions in one round trip, because they are asked together every
 * time: **who is here** (a heartbeat that upserts the caller's own row and
 * reads back everyone else's live ones) and **has this moved** (the note's
 * current version and `updated_at`, so the client can tell — without waiting
 * for its own save to bounce off a 409 — that someone else's edit has already
 * landed).
 *
 * What this is not: character-level co-editing. Two people typing in the same
 * paragraph at the same moment still resolve the way {@see NotesService}
 * always has, through the version check and the conflict banner. Presence
 * tells you someone else is here; it does not merge what they type with what
 * you type. Promising that would need an actual CRDT and a wire protocol for
 * it, which is a different feature — see docs/REALTIME.md for exactly where
 * the line sits and why it sits there.
 */
final class PresenceService
{
    /** A row older than this cannot mean "still here" — see docs/REALTIME.md for the interval this is sized against. */
    private const ACTIVE_WINDOW_SECONDS = 20;

    public function __construct(private readonly NotePermissionService $permissions = new NotePermissionService())
    {
    }

    /**
     * Record that the caller is here, and answer with everyone else who is,
     * plus the note's current version.
     *
     * @return array{viewers: array<int, array<string, mixed>>, version: int, updated_at: string}
     */
    public function sync(Identity $identity, string $noteId): array
    {
        Features::require(Features::REALTIME);

        // VIEW, the same floor as reading the note at all: presence about a
        // note is not more sensitive than the note itself, and someone who
        // cannot open it must not learn who else can.
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: 'n.id, n.version, n.updated_at',
        );

        RateLimiter::hit('presence', $identity->userId);

        Connection::execute(
            'INSERT INTO note_presence (note_id, user_id, display_name, last_seen_at)
             VALUES (:note_id, :user_id, :display_name, now())
             ON CONFLICT (note_id, user_id)
             DO UPDATE SET display_name = excluded.display_name, last_seen_at = now()',
            [
                'note_id' => $noteId,
                'user_id' => $identity->userId,
                // Refreshed every heartbeat, so a display-name change reaches
                // other viewers within one poll interval rather than waiting
                // for them to reload.
                'display_name' => $identity->displayName !== '' ? $identity->displayName : 'Someone',
            ],
        );

        $rows = Connection::select(
            'SELECT user_id, display_name, last_seen_at FROM note_presence
             WHERE note_id = :note_id
               AND user_id <> :self
               AND last_seen_at > now() - make_interval(secs => :window)
             ORDER BY last_seen_at DESC',
            ['note_id' => $noteId, 'self' => $identity->userId, 'window' => self::ACTIVE_WINDOW_SECONDS],
        );

        return [
            'viewers' => array_map(
                static fn (array $row): array => [
                    'user_id' => (string) $row['user_id'],
                    'display_name' => (string) $row['display_name'],
                ],
                $rows,
            ),
            'version' => (int) $note['version'],
            'updated_at' => (string) $note['updated_at'],
        ];
    }

    /**
     * The caller is gone: closed the note, closed the tab, signed out.
     *
     * Best-effort and permission-free by design. This deletes exactly one row
     * — the caller's own — addressed by their own id, so there is nothing for
     * a capability check to protect; requiring one would only mean a person
     * who just lost access to a note (removed as a collaborator, the note
     * went private) could not clean up the one trace of themselves it left
     * behind. A tab that closes without calling this at all is still covered:
     * the row ages out of {@see sync()}'s active window in seconds, and the
     * worker's maintenance sweep removes it for good.
     */
    public function leave(Identity $identity, string $noteId): void
    {
        Connection::execute(
            'DELETE FROM note_presence WHERE note_id = :note_id AND user_id = :user_id',
            ['note_id' => $noteId, 'user_id' => $identity->userId],
        );
    }

    /**
     * Drop presence rows old enough that no client is still polling for them.
     *
     * Run from {@see \Aicountly\Api\Domain\Jobs\Worker::maintenance()}. Set
     * well past {@see ACTIVE_WINDOW_SECONDS} — this is table hygiene, not the
     * "who is active" cutoff, which every {@see sync()} call already applies
     * on read regardless of what this sweep has gotten to.
     */
    public function sweep(): int
    {
        return Connection::execute("DELETE FROM note_presence WHERE last_seen_at < now() - interval '10 minutes'");
    }
}
