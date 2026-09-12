<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Tags\TagService;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * A tag's note count must only count notes the caller can still open.
 */
final class TagCountTest extends TestCase
{
    public function name(): string
    {
        return 'Tag counts';
    }

    public function testCountDropsWhenAccessIsRevoked(): void
    {
        $alice = new ApiClient(Support::user('a'));
        $bobIdentity = Support::user('b');
        $bob = new ApiClient($bobIdentity);
        $tags = new TagService();

        $note = $alice->post('/notes', ['document' => Support::doc('shared work')])['body']['data'];

        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, \'editor\', \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $note['id'], 'user' => 'user-b'],
        );

        // Bob files the shared note under his own tag.
        $tags->setForNote($bobIdentity, $note['id'], ['project-x']);

        $before = $tags->listForUser($bobIdentity);
        $this->assertCount(1, $before);
        $this->assertSame(1, $before[0]['note_count'], 'Bob can see the note, so it counts');

        Connection::execute('DELETE FROM note_members WHERE note_id = :n', ['n' => $note['id']]);

        $after = $tags->listForUser($bobIdentity);
        $this->assertSame(0, $after[0]['note_count'], 'access revoked, so it must stop counting');

        // And the note itself is gone from Bob's world entirely.
        $this->assertSame(404, $bob->get('/notes/' . $note['id'])['status']);
    }
}
