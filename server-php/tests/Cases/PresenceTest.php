<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Presence: who else has a note open, and has it moved since you last saw it.
 *
 * The properties worth holding: a stranger learns nothing about who is
 * viewing a note they cannot open, a viewer never sees themselves in their
 * own list, someone who has gone quiet drops off it, and the whole feature
 * is invisible — not merely inert — when the flag is off.
 */
final class PresenceTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Presence';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
        putenv('NOTES_REALTIME_ENABLED=true');
    }

    public function tearDown(): void
    {
        putenv('NOTES_REALTIME_ENABLED=false');
    }

    /** @return array<string, mixed> */
    private function note(): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Board call notes',
            'document' => Support::doc('Draft agenda'),
        ])['body']['data'];
    }

    private function share(string $noteId, string $userId, string $role = 'editor'): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, :role, \'user-a\')',
            ['id' => \Aicountly\Api\Support\Uuid::v4(), 'note' => $noteId, 'user' => $userId, 'role' => $role],
        );
    }

    // -- The flag -------------------------------------------------------

    public function testInvisibleRatherThanInertWhenTheFlagIsOff(): void
    {
        putenv('NOTES_REALTIME_ENABLED=false');
        $note = $this->note();

        $result = $this->alice->post('/notes/' . $note['id'] . '/presence');

        $this->assertSame(503, $result['status']);
        $this->assertSame('FEATURE_DISABLED', $result['body']['error']['code']);
    }

    // -- The heartbeat ----------------------------------------------------

    public function testAHeartbeatNeverListsYourself(): void
    {
        $note = $this->note();

        $result = $this->alice->post('/notes/' . $note['id'] . '/presence');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data'], 'the only viewer so far is the caller, and the caller is excluded');
        $this->assertSame(1, $result['body']['meta']['version']);
    }

    public function testTwoPeopleSeeEachOtherAndNotThemselves(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');

        $this->alice->post('/notes/' . $note['id'] . '/presence');
        $bobsView = $this->bob->post('/notes/' . $note['id'] . '/presence');
        $alicesView = $this->alice->post('/notes/' . $note['id'] . '/presence');

        $this->assertSame(['user-a'], array_column($bobsView['body']['data'], 'user_id'));
        $this->assertSame('User A', $bobsView['body']['data'][0]['display_name']);
        $this->assertSame(['user-b'], array_column($alicesView['body']['data'], 'user_id'));
    }

    public function testASecondTabFromTheSameUserIsOneRowNotTwo(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');

        // Two heartbeats from Bob, as two open tabs would send.
        $this->bob->post('/notes/' . $note['id'] . '/presence');
        $this->bob->post('/notes/' . $note['id'] . '/presence');

        $alicesView = $this->alice->post('/notes/' . $note['id'] . '/presence');

        $this->assertCount(1, $alicesView['body']['data'], 'one person, however many tabs');
    }

    public function testSomeoneWhoHasGoneQuietDropsOffTheList(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');
        $this->bob->post('/notes/' . $note['id'] . '/presence');

        // Bob's tab was closed a while ago and never called DELETE — a
        // crashed tab, a lost connection. Nothing renewed his row.
        Connection::execute(
            "UPDATE note_presence SET last_seen_at = now() - interval '5 minutes' WHERE note_id = :id AND user_id = 'user-b'",
            ['id' => $note['id']],
        );

        $alicesView = $this->alice->post('/notes/' . $note['id'] . '/presence');

        $this->assertSame([], $alicesView['body']['data']);
    }

    public function testLeavingRemovesTheRowImmediately(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');
        $this->bob->post('/notes/' . $note['id'] . '/presence');

        $left = $this->bob->delete('/notes/' . $note['id'] . '/presence');
        $this->assertSame(204, $left['status']);

        $alicesView = $this->alice->post('/notes/' . $note['id'] . '/presence');
        $this->assertSame([], $alicesView['body']['data']);
    }

    /** Cleaning up your own trace is not gated on still having access. */
    public function testLeavingWorksEvenAfterAccessWasRevoked(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');
        $this->bob->post('/notes/' . $note['id'] . '/presence');

        Connection::execute(
            "DELETE FROM note_members WHERE note_id = :id AND user_id = 'user-b'",
            ['id' => $note['id']],
        );

        $left = $this->bob->delete('/notes/' . $note['id'] . '/presence');
        $this->assertSame(204, $left['status']);
    }

    // -- The version signal -----------------------------------------------

    public function testTheVersionMovesWhenTheNoteIsSaved(): void
    {
        $note = $this->note();

        $before = $this->alice->post('/notes/' . $note['id'] . '/presence');
        $this->assertSame(1, $before['body']['meta']['version']);

        $this->alice->patch('/notes/' . $note['id'], [
            'document' => Support::doc('Revised agenda'),
            'version' => 1,
        ]);

        $after = $this->alice->post('/notes/' . $note['id'] . '/presence');
        $this->assertSame(2, $after['body']['meta']['version']);
        $this->assertNotSame($before['body']['meta']['updated_at'], $after['body']['meta']['updated_at']);
    }

    // -- Access control -----------------------------------------------------

    public function testAStrangerLearnsNothingAboutWhoIsViewing(): void
    {
        $note = $this->note();
        $this->alice->post('/notes/' . $note['id'] . '/presence');

        $carol = new ApiClient(Support::user('c'));
        $result = $carol->post('/notes/' . $note['id'] . '/presence');

        // Not merely refused: indistinguishable from a note that does not
        // exist, the same rule every other endpoint in this API follows.
        $this->assertSame(404, $result['status']);
        $this->assertSame('NOT_FOUND', $result['body']['error']['code']);
    }

    public function testAStrangerCannotLeaveANoteTheyWereNeverOn(): void
    {
        $note = $this->note();

        $carol = new ApiClient(Support::user('c'));
        // No permission check on the delete path (see PresenceService::leave),
        // so this is a no-op rather than a 404 — but it must still be a no-op,
        // never a way to evict someone else's presence row.
        $result = $carol->delete('/notes/' . $note['id'] . '/presence');
        $this->assertSame(204, $result['status']);

        $this->alice->post('/notes/' . $note['id'] . '/presence');
        $stillThere = Connection::selectOne(
            "SELECT 1 AS present FROM note_presence WHERE note_id = :id AND user_id = 'user-a'",
            ['id' => $note['id']],
        );
        $this->assertNotNull($stillThere);
    }

    public function testAViewerWithoutEditRightsStillAppearsInPresence(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'viewer');

        $this->bob->post('/notes/' . $note['id'] . '/presence');
        $alicesView = $this->alice->post('/notes/' . $note['id'] . '/presence');

        // Presence is gated on VIEW, the same floor as opening the note at
        // all — not on EDIT, which would hide read-only collaborators from a
        // feature whose entire point is knowing who is looking at the note
        // with you.
        $this->assertSame(['user-b'], array_column($alicesView['body']['data'], 'user_id'));
    }

    // -- Housekeeping ---------------------------------------------------

    public function testTheMaintenanceSweepRemovesOldRowsButNotFreshOnes(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b');
        $this->alice->post('/notes/' . $note['id'] . '/presence');
        $this->bob->post('/notes/' . $note['id'] . '/presence');

        Connection::execute(
            "UPDATE note_presence SET last_seen_at = now() - interval '1 hour' WHERE note_id = :id AND user_id = 'user-a'",
            ['id' => $note['id']],
        );

        $swept = (new \Aicountly\Api\Domain\Collaboration\PresenceService())->sweep();

        $this->assertSame(1, $swept);
        $remaining = Connection::select('SELECT user_id FROM note_presence WHERE note_id = :id', ['id' => $note['id']]);
        $this->assertSame(['user-b'], array_column($remaining, 'user_id'));
    }
}
