<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * A note's activity trail over the real router.
 *
 * The trail is shown to everyone on a note, so two properties are load-bearing:
 * it never carries what the note says, and it is only readable by someone who
 * could open the note anyway.
 */
final class ActivityTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Activity';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    /** A note with three things having happened to it, in a known order. */
    private function noteWithHistory(): array
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Meeting with ABC',
            'document' => Support::doc('bank password is hunter2'),
        ])['body']['data'];

        $this->alice->patch('/notes/' . $note['id'], ['title' => 'Meeting with ABC Pvt Ltd']);
        $this->alice->post('/notes/' . $note['id'] . '/archive');

        return $note;
    }

    private function share(string $noteId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, \'user-b\', :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'role' => $role],
        );
    }

    public function testTheTrailReadsNewestFirst(): void
    {
        $note = $this->noteWithHistory();

        $result = $this->alice->get('/notes/' . $note['id'] . '/activity');
        $this->assertSame(200, $result['status']);

        $actions = array_map(static fn (array $row): string => $row['action'], $result['body']['data']);
        $this->assertSame(['note.archived', 'note.updated', 'note.created'], $actions);
        $this->assertSame('user-a', $result['body']['data'][0]['actor_user_id']);
        $this->assertNotNull($result['body']['data'][0]['created_at']);
        $this->assertSame('document', $result['body']['data'][2]['context']['note_type']);
    }

    public function testTheTrailNeverCarriesWhatTheNoteSays(): void
    {
        $note = $this->noteWithHistory();

        $encoded = (string) json_encode($this->alice->get('/notes/' . $note['id'] . '/activity')['body']);
        $this->assertFalse(str_contains($encoded, 'hunter2'), 'the body must never reach the trail');
        $this->assertFalse(str_contains($encoded, 'Meeting with ABC'), 'nor the title');
    }

    public function testPagesThroughTheTrail(): void
    {
        $note = $this->noteWithHistory();

        $first = $this->alice->get('/notes/' . $note['id'] . '/activity', ['limit' => 2]);
        $this->assertCount(2, $first['body']['data']);
        $this->assertSame(3, $first['body']['meta']['total']);
        $this->assertTrue($first['body']['meta']['has_more']);

        $second = $this->alice->get('/notes/' . $note['id'] . '/activity', ['limit' => 2, 'offset' => 2]);
        $this->assertCount(1, $second['body']['data']);
        $this->assertFalse($second['body']['meta']['has_more']);
        $this->assertSame('note.created', $second['body']['data'][0]['action'], 'the oldest entry is last');
    }

    /**
     * A page past the end still knows how long the trail is.
     *
     * `total` came from a window function on the returned rows, so an offset
     * beyond the last entry — a client scrolled deep, or one re-reading a
     * position after entries were collapsed — reported a trail of zero on a
     * note with a full history, and "no activity yet" is what the panel would
     * then say.
     */
    public function testAPagePastTheEndStillReportsTheTotal(): void
    {
        $note = $this->noteWithHistory();

        $beyond = $this->alice->get('/notes/' . $note['id'] . '/activity', ['limit' => 10, 'offset' => 50]);

        $this->assertSame(200, $beyond['status']);
        $this->assertCount(0, $beyond['body']['data']);
        $this->assertSame(3, $beyond['body']['meta']['total'], 'the trail is still three entries long');
        $this->assertFalse($beyond['body']['meta']['has_more']);

        // And the count behind it is a read like any other: it is gated by the
        // same access CTE, not by the caller having asked nicely.
        $this->assertSame(404, $this->bob->get(
            '/notes/' . $note['id'] . '/activity',
            ['offset' => 50],
        )['status']);
    }

    public function testOnlySomeoneWhoCanOpenTheNoteCanReadItsTrail(): void
    {
        $note = $this->noteWithHistory();

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/activity')['status']);

        // A viewer may read it: the trail is how a collaborator sees who else
        // has been in the note.
        $this->share($note['id'], 'viewer');
        $shared = $this->bob->get('/notes/' . $note['id'] . '/activity');
        $this->assertSame(200, $shared['status']);
        $this->assertCount(3, $shared['body']['data']);
    }

    public function testTheTrailDoesNotCrossATenantBoundary(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $note = $acme->post('/notes', ['document' => Support::doc('acme numbers')])['body']['data'];

        $globex = new ApiClient(Support::user('a', 'tenant-globex'));
        $this->assertSame(404, $globex->get('/notes/' . $note['id'] . '/activity')['status']);
    }

    public function testAMalformedNoteIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/notes/not-a-uuid/activity')['status']);
        $this->assertSame(404, $this->alice->get('/notes/' . Uuid::v4() . '/activity')['status']);
    }
}
