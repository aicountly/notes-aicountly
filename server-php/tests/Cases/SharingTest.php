<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Sharing a note, over the real router.
 *
 * Sharing is the one act in this product that cannot be undone for anything
 * already read, so these cases are written from the side that must fail: an
 * editor trying to re-share, a stranger trying to read the member list, a
 * request trying to grant ownership, a removed member trying one more call.
 */
final class SharingTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;
    private ApiClient $carol;

    public function name(): string
    {
        return 'Sharing';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
        $this->carol = new ApiClient(Support::user('c'));
    }

    /** @return array<string, mixed> */
    private function note(): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Quarterly close',
            'document' => Support::doc('numbers nobody else should read'),
        ])['body']['data'];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function share(string $noteId, string $userId, string $role): array
    {
        return $this->alice->post('/notes/' . $noteId . '/members', ['user_id' => $userId, 'role' => $role]);
    }

    // -- The happy path -----------------------------------------------------

    public function testTheOwnerIsListedOnANoteSharedWithNobody(): void
    {
        $note = $this->note();

        $members = $this->alice->get('/notes/' . $note['id'] . '/members');
        $this->assertSame(200, $members['status']);
        $this->assertCount(1, $members['body']['data']);
        $this->assertSame('user-a', $members['body']['data'][0]['user_id']);
        $this->assertSame('owner', $members['body']['data'][0]['role']);
        $this->assertNull($members['body']['data'][0]['invited_by']);
        $this->assertTrue($members['body']['data'][0]['capabilities']['manage_members']);
    }

    public function testSharingGivesTheRecipientExactlyTheRoleGranted(): void
    {
        $note = $this->note();

        $shared = $this->share($note['id'], 'user-b', 'viewer');
        $this->assertSame(201, $shared['status']);
        $this->assertSame('viewer', $shared['body']['data']['role']);
        $this->assertSame('user-a', $shared['body']['data']['invited_by']);

        $read = $this->bob->get('/notes/' . $note['id']);
        $this->assertSame(200, $read['status']);
        $this->assertSame('viewer', $read['body']['data']['role']);
        $this->assertFalse($read['body']['data']['capabilities']['edit']);
    }

    public function testSharingWithTheSamePersonTwiceChangesTheirRole(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'viewer');

        $again = $this->share($note['id'], 'user-b', 'editor');
        // 200, not 201: the second call edited a grant rather than making one.
        $this->assertSame(200, $again['status']);
        $this->assertSame('editor', $again['body']['data']['role']);

        $this->assertCount(2, $this->alice->get('/notes/' . $note['id'] . '/members')['body']['data']);
        $this->assertSame(200, $this->bob->patch('/notes/' . $note['id'], ['title' => 'Edited by Bob'])['status']);
    }

    public function testPatchChangesARoleAndRefusesSomebodyWhoIsNotAMember(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'editor');

        $patched = $this->alice->patch('/notes/' . $note['id'] . '/members/user-b', ['role' => 'commenter']);
        $this->assertSame(200, $patched['status']);
        $this->assertSame('commenter', $patched['body']['data']['role']);

        // The downgrade is live on the very next call.
        $this->assertSame(403, $this->bob->patch('/notes/' . $note['id'], ['title' => 'nope'])['status']);

        $stranger = $this->alice->patch('/notes/' . $note['id'] . '/members/user-c', ['role' => 'viewer']);
        $this->assertSame(404, $stranger['status'], 'PATCH must not quietly create a grant');
    }

    // -- Who may manage members ---------------------------------------------

    public function testAnEditorMaySeeTheMemberListButNotChangeIt(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'editor');

        // Visible: a collaborator who cannot see who else is in a note has no
        // way to judge what is safe to write in it.
        $this->assertSame(200, $this->bob->get('/notes/' . $note['id'] . '/members')['status']);

        $added = $this->bob->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-c', 'role' => 'editor']);
        $this->assertSame(403, $added['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $added['body']['error']['code']);
        $this->assertContainsString('Only the owner can change sharing', $added['body']['error']['message']);

        $this->assertSame(403, $this->bob->patch('/notes/' . $note['id'] . '/members/user-b', ['role' => 'owner'])['status']);
        $this->assertSame(403, $this->bob->delete('/notes/' . $note['id'] . '/members/user-b')['status']);

        // Nothing the editor tried took effect.
        $this->assertCount(2, $this->alice->get('/notes/' . $note['id'] . '/members')['body']['data']);
        $this->assertSame(404, $this->carol->get('/notes/' . $note['id'])['status']);
    }

    public function testAStrangerCannotReachTheMemberListAtAll(): void
    {
        $note = $this->note();

        // 404 everywhere: guessing note ids must not reveal which notes exist,
        // let alone who they are shared with.
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/members')['status']);
        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'editor'])['status']);
        $this->assertSame(404, $this->bob->patch('/notes/' . $note['id'] . '/members/user-b', ['role' => 'editor'])['status']);
        $this->assertSame(404, $this->bob->delete('/notes/' . $note['id'] . '/members/user-a')['status']);

        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/members')['body']['data']);
    }

    // -- What may be granted ------------------------------------------------

    public function testGrantingOwnershipIsRefused(): void
    {
        $note = $this->note();

        foreach (['owner', 'admin', '', 'Editor'] as $role) {
            $result = $this->share($note['id'], 'user-b', $role);
            $this->assertSame(422, $result['status'], 'role "' . $role . '" must not be grantable');
            $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
        }

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'])['status']);
    }

    public function testYouCannotShareANoteWithYourselfOrWithItsOwner(): void
    {
        $note = $this->note();

        $self = $this->share($note['id'], 'user-a', 'editor');
        $this->assertSame(400, $self['status']);
        $this->assertContainsString('already have access', $self['body']['error']['message']);

        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/members')['body']['data']);
    }

    public function testAMemberIdMustLookLikeOne(): void
    {
        $note = $this->note();

        $this->assertSame(422, $this->share($note['id'], '', 'viewer')['status']);
        $this->assertSame(422, $this->share($note['id'], str_repeat('x', 65), 'viewer')['status']);
    }

    // -- Removal ------------------------------------------------------------

    public function testRemovingAMemberTakesEffectOnTheVeryNextCall(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'editor');
        $this->assertSame(200, $this->bob->get('/notes/' . $note['id'])['status']);

        $this->assertSame(204, $this->alice->delete('/notes/' . $note['id'] . '/members/user-b')['status']);

        // No cache stands between a revoked grant and the next request: the
        // session cache remembers who the caller is, never what they may open.
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'])['status']);
        $this->assertCount(0, $this->bob->get('/notes')['body']['data']);
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/comments')['status']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/members')['body']['data']);
    }

    public function testTheOwnerCannotBeRemovedFromTheirOwnNote(): void
    {
        $note = $this->note();

        $removed = $this->alice->delete('/notes/' . $note['id'] . '/members/user-a');
        $this->assertSame(400, $removed['status']);
        $this->assertSame('BAD_REQUEST', $removed['body']['error']['code']);

        // Still theirs, still shareable.
        $this->assertSame(200, $this->alice->get('/notes/' . $note['id'])['status']);
        $this->assertSame(201, $this->share($note['id'], 'user-b', 'viewer')['status']);
    }

    public function testRemovingSomebodyWhoWasNeverAMemberIsNotFound(): void
    {
        $note = $this->note();

        $this->assertSame(404, $this->alice->delete('/notes/' . $note['id'] . '/members/user-c')['status']);
    }

    // -- The trail ----------------------------------------------------------

    public function testEveryChangeToSharingIsRecorded(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'viewer');
        $this->share($note['id'], 'user-b', 'editor');
        $this->alice->delete('/notes/' . $note['id'] . '/members/user-b');

        $entries = $this->alice->get('/notes/' . $note['id'] . '/activity')['body']['data'];
        $actions = array_map(static fn (array $row): string => $row['action'], $entries);

        $this->assertSame(['member.removed', 'member.role_changed', 'member.added', 'note.created'], $actions);
        $this->assertSame('user-b', $entries[1]['context']['member_user_id']);
        $this->assertSame('viewer', $entries[1]['context']['previous_role']);
    }

    // -- Abuse --------------------------------------------------------------

    public function testSharingIsRateLimited(): void
    {
        $note = $this->note();

        // Each grant is a notification to somebody else's inbox, so the bucket
        // is what stops one note being shared with a thousand ids.
        for ($i = 0; $i < 60; $i++) {
            $status = $this->share($note['id'], 'user-b', $i % 2 === 0 ? 'viewer' : 'editor')['status'];
            if ($status === 429) {
                $this->fail('the share bucket closed after ' . $i . ' calls, before its limit');

                return;
            }
        }

        $limited = $this->share($note['id'], 'user-b', 'viewer');
        $this->assertSame(429, $limited['status']);
        $this->assertSame('RATE_LIMITED', $limited['body']['error']['code']);

        // Revocation is deliberately outside the bucket: an owner who has been
        // busy sharing must still be able to close a note.
        $this->assertSame(204, $this->alice->delete('/notes/' . $note['id'] . '/members/user-b')['status']);
    }

    public function testAMalformedNoteIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/notes/not-a-uuid/members')['status']);
        $this->assertSame(404, $this->alice->get('/notes/' . Uuid::v4() . '/members')['status']);
    }
}
