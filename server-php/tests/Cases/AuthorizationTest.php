<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Who can do what.
 *
 * These are the tests that matter most in this product: a notes app holds
 * private thinking, and the failure mode is not a broken screen but somebody
 * reading something they should not. Each case is written from the attacker's
 * side — "user B tries X" — rather than from the happy path.
 */
final class AuthorizationTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Authorization';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    private function aliceNote(string $text = 'private thoughts'): array
    {
        return $this->alice->post('/notes', ['title' => 'Alice note', 'document' => Support::doc($text)])['body']['data'];
    }

    private function share(string $noteId, string $userId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'user' => $userId, 'role' => $role],
        );
    }

    // -- No grant at all ----------------------------------------------------

    public function testAStrangerCannotReadANote(): void
    {
        $note = $this->aliceNote();

        $result = $this->bob->get('/notes/' . $note['id']);
        // 404, not 403: guessing ids must not reveal which notes exist.
        $this->assertSame(404, $result['status']);
        $this->assertSame('NOT_FOUND', $result['body']['error']['code']);
    }

    public function testAStrangerCannotEditOrDeleteANote(): void
    {
        $note = $this->aliceNote();

        $this->assertSame(404, $this->bob->patch('/notes/' . $note['id'], ['title' => 'hijacked'])['status']);
        $this->assertSame(404, $this->bob->delete('/notes/' . $note['id'])['status']);
        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/duplicate')['status']);
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/versions')['status']);

        $unchanged = $this->alice->get('/notes/' . $note['id'])['body']['data'];
        $this->assertSame('Alice note', $unchanged['title']);
    }

    public function testAStrangersNotesNeverAppearInTheList(): void
    {
        $this->aliceNote('alpha');
        $this->aliceNote('beta');

        $this->assertCount(0, $this->bob->get('/notes')['body']['data']);
        $this->assertCount(0, $this->bob->get('/notes', ['q' => 'alpha'])['body']['data']);
        $this->assertSame(0, $this->bob->get('/notes/counts')['body']['data']['active']);
    }

    // -- Roles --------------------------------------------------------------

    public function testAViewerCanReadButNotEdit(): void
    {
        $note = $this->aliceNote();
        $this->share($note['id'], 'user-b', 'viewer');

        $read = $this->bob->get('/notes/' . $note['id']);
        $this->assertSame(200, $read['status']);
        $this->assertSame('viewer', $read['body']['data']['role']);
        $this->assertFalse($read['body']['data']['capabilities']['edit']);

        $write = $this->bob->patch('/notes/' . $note['id'], ['title' => 'nope']);
        $this->assertSame(403, $write['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $write['body']['error']['code']);
    }

    public function testACommenterCanReadButNotEditTheBody(): void
    {
        $note = $this->aliceNote();
        $this->share($note['id'], 'user-b', 'commenter');

        $read = $this->bob->get('/notes/' . $note['id']);
        $this->assertSame(200, $read['status']);
        $this->assertTrue($read['body']['data']['capabilities']['comment']);
        $this->assertFalse($read['body']['data']['capabilities']['edit']);

        $write = $this->bob->patch('/notes/' . $note['id'], ['document' => Support::doc('rewritten')]);
        $this->assertSame(403, $write['status']);
        $this->assertContainsString('comment on this note, but not edit', $write['body']['error']['message']);
    }

    public function testAnEditorCanEditButNotShareOrDelete(): void
    {
        $note = $this->aliceNote();
        $this->share($note['id'], 'user-b', 'editor');

        $edit = $this->bob->patch('/notes/' . $note['id'], [
            'document' => Support::doc('edited by bob'),
            'version' => $note['version'],
        ]);
        $this->assertSame(200, $edit['status']);
        $this->assertContainsString('edited by bob', $edit['body']['data']['excerpt']);

        // Deleting and re-sharing stay with the owner: an editor who could
        // re-share would be able to widen an audience the owner chose.
        $this->assertSame(403, $this->bob->delete('/notes/' . $note['id'])['status']);
        $this->assertFalse($edit['body']['data']['capabilities']['share']);
    }

    public function testRevokingAccessTakesEffectImmediately(): void
    {
        $note = $this->aliceNote();
        $this->share($note['id'], 'user-b', 'editor');
        $this->assertSame(200, $this->bob->get('/notes/' . $note['id'])['status']);

        Connection::execute('DELETE FROM note_members WHERE note_id = :n', ['n' => $note['id']]);

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'])['status']);
        $this->assertCount(0, $this->bob->get('/notes')['body']['data']);
    }

    // -- Notebook cascade ---------------------------------------------------

    public function testANotebookShareCascadesToItsNotesAndSubNotebooks(): void
    {
        $parentId = Uuid::v4();
        $childId = Uuid::v4();
        Connection::execute(
            'INSERT INTO notebooks (id, owner_user_id, name, created_by, updated_by)
             VALUES (:id, \'user-a\', \'Projects\', \'user-a\', \'user-a\')',
            ['id' => $parentId],
        );
        Connection::execute(
            'INSERT INTO notebooks (id, owner_user_id, parent_id, name, depth, created_by, updated_by)
             VALUES (:id, \'user-a\', :parent, \'Notes\', 1, \'user-a\', \'user-a\')',
            ['id' => $childId, 'parent' => $parentId],
        );

        $inParent = $this->alice->post('/notes', ['notebook_id' => $parentId, 'document' => Support::doc('parent note')])['body']['data'];
        $inChild = $this->alice->post('/notes', ['notebook_id' => $childId, 'document' => Support::doc('child note')])['body']['data'];

        $this->assertSame(404, $this->bob->get('/notes/' . $inChild['id'])['status']);

        Connection::execute(
            'INSERT INTO notebook_members (id, notebook_id, user_id, role, invited_by)
             VALUES (:id, :nb, \'user-b\', \'editor\', \'user-a\')',
            ['id' => Uuid::v4(), 'nb' => $parentId],
        );

        $this->assertSame(200, $this->bob->get('/notes/' . $inParent['id'])['status']);
        $this->assertSame(200, $this->bob->get('/notes/' . $inChild['id'])['status'], 'the share cascades to sub-notebooks');
        $this->assertCount(2, $this->bob->get('/notes')['body']['data']);
    }

    // -- Tenancy ------------------------------------------------------------

    public function testOneTenantCannotReachAnothersNotes(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $globex = new ApiClient(Support::user('b', 'tenant-globex'));

        $note = $acme->post('/notes', ['document' => Support::doc('acme quarterly numbers')])['body']['data'];

        $this->assertSame(404, $globex->get('/notes/' . $note['id'])['status']);
        $this->assertCount(0, $globex->get('/notes')['body']['data']);
    }

    public function testATenantGrantDoesNotSurviveACrossTenantMembership(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $note = $acme->post('/notes', ['document' => Support::doc('acme secrets')])['body']['data'];

        // Even an explicit membership row cannot reach across the boundary:
        // the tenant gate is an AND on top of every grant.
        $this->share($note['id'], 'user-b', 'editor');

        $globex = new ApiClient(Support::user('b', 'tenant-globex'));
        $this->assertSame(404, $globex->get('/notes/' . $note['id'])['status']);

        // The same user acting inside Acme does get in.
        $insideAcme = new ApiClient(Support::user('b', 'tenant-acme'));
        $this->assertSame(200, $insideAcme->get('/notes/' . $note['id'])['status']);
    }

    public function testPersonalNotesStayVisibleWhicheverCompanyIsActive(): void
    {
        $personal = new ApiClient(Support::user('a'));
        $note = $personal->post('/notes', ['document' => Support::doc('my own note')])['body']['data'];

        $atWork = new ApiClient(Support::user('a', 'tenant-acme'));
        $this->assertSame(200, $atWork->get('/notes/' . $note['id'])['status']);
    }

    // -- Trash and archive keep their guards --------------------------------

    public function testATrashedNoteIsStillPermissionProtected(): void
    {
        $note = $this->aliceNote();
        $this->share($note['id'], 'user-b', 'viewer');
        $this->alice->delete('/notes/' . $note['id']);

        $this->assertCount(0, $this->bob->get('/notes', ['scope' => 'trash'])['body']['data']);
        $this->assertSame(403, $this->bob->post('/notes/' . $note['id'] . '/restore')['status']);
    }

    public function testAnArchivedNoteIsStillPermissionProtected(): void
    {
        $note = $this->aliceNote();
        $this->alice->post('/notes/' . $note['id'] . '/archive');

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'])['status']);
        $this->assertCount(0, $this->bob->get('/notes', ['scope' => 'archive'])['body']['data']);
    }

    public function testAMalformedNoteIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/notes/not-a-uuid')['status']);
        $this->assertSame(404, $this->alice->get('/notes/' . Uuid::v4())['status']);
    }

    public function testFilingIntoANotebookYouCannotWriteIsRefused(): void
    {
        $notebookId = Uuid::v4();
        Connection::execute(
            'INSERT INTO notebooks (id, owner_user_id, name, created_by, updated_by)
             VALUES (:id, \'user-a\', \'Alice private\', \'user-a\', \'user-a\')',
            ['id' => $notebookId],
        );

        $result = $this->bob->post('/notes', ['notebook_id' => $notebookId, 'document' => Support::doc('sneaky')]);
        $this->assertSame(404, $result['status'], 'a notebook you cannot see cannot be filed into');
    }
}
