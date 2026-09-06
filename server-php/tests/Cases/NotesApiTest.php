<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * The core note lifecycle, over the real router and the real database.
 */
final class NotesApiTest extends TestCase
{
    private ApiClient $api;

    public function name(): string
    {
        return 'Notes API';
    }

    public function setUp(): void
    {
        $this->api = new ApiClient(Support::user('a'));
    }

    public function testCreatesANoteAndReadsItBack(): void
    {
        $created = $this->api->post('/notes', [
            'title' => 'Meeting with ABC Pvt Ltd',
            'document' => Support::doc('Discussed GST reconciliation.'),
        ]);

        $this->assertSame(201, $created['status']);
        $note = $created['body']['data'];
        $this->assertTrue(Uuid::isValid($note['id']));
        $this->assertSame(1, $note['version']);
        $this->assertSame('Meeting with ABC Pvt Ltd', $note['title']);

        $fetched = $this->api->get('/notes/' . $note['id']);
        $this->assertSame(200, $fetched['status']);
        $this->assertContainsString('GST reconciliation', $fetched['body']['data']['excerpt']);
        // The document survives the round trip as structure, not as markup.
        $this->assertSame('doc', $fetched['body']['data']['document']['type']);
    }

    public function testAnUntitledNoteShowsItsFirstLine(): void
    {
        $note = $this->api->post('/notes', [
            'document' => Support::doc("Buy milk\nand bread"),
        ])['body']['data'];

        $this->assertNull($note['title']);
        $this->assertSame('Buy milk', $note['display_title']);
    }

    public function testCreateIsIdempotentForAQueuedOfflineId(): void
    {
        $id = Uuid::v4();
        $first = $this->api->post('/notes', ['id' => $id, 'document' => Support::doc('offline note')]);
        $second = $this->api->post('/notes', ['id' => $id, 'document' => Support::doc('offline note')]);

        $this->assertSame($id, $first['body']['data']['id']);
        $this->assertSame($id, $second['body']['data']['id']);

        $count = Connection::selectOne('SELECT count(*) AS c FROM notes');
        $this->assertSame(1, (int) $count['c'], 'a replayed create must not duplicate the note');
    }

    public function testUpdateBumpsTheVersionAndKeepsTheText(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('first')])['body']['data'];

        $updated = $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('second draft'),
            'version' => $note['version'],
        ])['body']['data'];

        $this->assertSame(2, $updated['version']);
        $this->assertContainsString('second draft', $updated['excerpt']);
    }

    public function testAStaleVersionIsRefusedRatherThanOverwriting(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('original')])['body']['data'];

        // Another client saves first.
        $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('someone else wrote this'),
            'version' => 1,
        ]);

        // This client still thinks it is on version 1.
        $conflict = $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('my stale draft'),
            'version' => 1,
        ]);

        $this->assertSame(409, $conflict['status']);
        $this->assertSame('VERSION_CONFLICT', $conflict['body']['error']['code']);
        // The response carries the server's copy so the client can recover
        // rather than just losing the edit.
        $this->assertSame(2, $conflict['body']['error']['details']['server_version']);
        $this->assertContainsString(
            'someone else wrote this',
            $conflict['body']['error']['details']['note']['excerpt'],
        );

        $current = $this->api->get('/notes/' . $note['id'])['body']['data'];
        $this->assertContainsString('someone else wrote this', $current['excerpt'], 'the newer text survived');
    }

    public function testMetadataTogglesDoNotNeedAVersion(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('x')])['body']['data'];

        $pinned = $this->api->post('/notes/' . $note['id'] . '/pin');
        $this->assertSame(200, $pinned['status']);
        $this->assertTrue($pinned['body']['data']['is_pinned']);
        // Pinning is not an edit, so the document version is untouched.
        $this->assertSame(1, $pinned['body']['data']['version']);

        $this->assertFalse($this->api->post('/notes/' . $note['id'] . '/unpin')['body']['data']['is_pinned']);
        $this->assertTrue($this->api->post('/notes/' . $note['id'] . '/favourite')['body']['data']['is_favourite']);
    }

    public function testTrashHidesTheNoteAndRestoreBringsItBack(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('temporary')])['body']['data'];

        $this->api->delete('/notes/' . $note['id']);

        $this->assertCount(0, $this->api->get('/notes')['body']['data'], 'trashed notes leave the list');
        $this->assertSame(404, $this->api->get('/notes/' . $note['id'])['status']);

        $trash = $this->api->get('/notes', ['scope' => 'trash'])['body']['data'];
        $this->assertCount(1, $trash);

        $this->api->post('/notes/' . $note['id'] . '/restore');
        $this->assertCount(1, $this->api->get('/notes')['body']['data']);
    }

    public function testPermanentDeleteRequiresTheNoteToBeInTrashFirst(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('x')])['body']['data'];

        $tooSoon = $this->api->delete('/notes/' . $note['id'], ['permanent' => 'true']);
        $this->assertSame(400, $tooSoon['status']);

        $this->api->delete('/notes/' . $note['id']);
        $this->assertSame(204, $this->api->delete('/notes/' . $note['id'], ['permanent' => 'true'])['status']);
        $this->assertSame(0, (int) Connection::selectOne('SELECT count(*) AS c FROM notes')['c']);
    }

    public function testArchiveRemovesANoteFromTheDefaultListButNotFromArchive(): void
    {
        $note = $this->api->post('/notes', ['document' => Support::doc('done with this')])['body']['data'];

        $this->api->post('/notes/' . $note['id'] . '/archive');
        $this->assertCount(0, $this->api->get('/notes')['body']['data']);
        $this->assertCount(1, $this->api->get('/notes', ['scope' => 'archive'])['body']['data']);

        $this->api->post('/notes/' . $note['id'] . '/unarchive');
        $this->assertCount(1, $this->api->get('/notes')['body']['data']);
    }

    public function testDuplicateCopiesContentButNotCollaborationState(): void
    {
        $note = $this->api->post('/notes', [
            'title' => 'Playbook',
            'document' => Support::doc('the original'),
            'tags' => ['gst', 'audit'],
        ])['body']['data'];

        // Give the original a reminder and a collaborator.
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id, :n, :u, \'viewer\', :i)',
            ['id' => Uuid::v4(), 'n' => $note['id'], 'u' => 'user-b', 'i' => 'user-a'],
        );

        $copy = $this->api->post('/notes/' . $note['id'] . '/duplicate')['body']['data'];

        $this->assertSame('Playbook (copy)', $copy['title']);
        $this->assertContainsString('the original', $copy['excerpt']);
        $this->assertCount(2, $copy['tags'], 'tags come across');

        $members = Connection::select('SELECT 1 FROM note_members WHERE note_id = :id', ['id' => $copy['id']]);
        $this->assertCount(0, $members, 'a duplicate must not silently re-share the note');
    }

    public function testVersionHistoryIsCheckpointedNotOnePerKeystroke(): void
    {
        $note = $this->api->post('/notes', ['title' => 'Draft', 'document' => Support::doc('v1')])['body']['data'];

        // Simulate autosave: several saves in quick succession by one author.
        $version = $note['version'];
        foreach (['v2', 'v3', 'v4'] as $text) {
            $result = $this->api->patch('/notes/' . $note['id'], [
                'document' => Support::doc($text),
                'version' => $version,
            ]);
            $version = $result['body']['data']['version'];
        }

        $versions = $this->api->get('/notes/' . $note['id'] . '/versions')['body']['data'];
        // The create writes one explicit checkpoint; the three autosaves
        // coalesce into a single moving one.
        $this->assertCount(2, $versions, 'autosaves within the window coalesce');
        $this->assertSame('manual', $versions[1]['reason']);
    }

    public function testRestoringAVersionBringsBackItsText(): void
    {
        $note = $this->api->post('/notes', ['title' => 'Contract', 'document' => Support::doc('good text')])['body']['data'];

        $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('accidental deletion'),
            'version' => $note['version'],
            'revision_reason' => 'manual',
        ]);

        $versions = $this->api->get('/notes/' . $note['id'] . '/versions')['body']['data'];
        $oldest = end($versions);

        $restored = $this->api->post('/notes/' . $note['id'] . '/versions/' . $oldest['id'] . '/restore');
        $this->assertSame(200, $restored['status']);
        $this->assertContainsString('good text', $restored['body']['data']['excerpt']);
    }

    public function testChecklistItemsBecomeTrackableActions(): void
    {
        $note = $this->api->post('/notes', [
            'note_type' => 'checklist',
            'document' => Support::checklist([
                ['text' => 'File GST return', 'checked' => false],
                ['text' => 'Email ABC', 'checked' => true],
            ]),
        ])['body']['data'];

        $actions = $this->api->get('/notes/' . $note['id'] . '/actions')['body']['data'];
        $this->assertCount(2, $actions);
        $this->assertSame('open', $actions[0]['status']);
        $this->assertSame('done', $actions[1]['status']);

        $list = $this->api->get('/notes')['body']['data'];
        $this->assertSame(2, $list[0]['checklist']['total']);
        $this->assertSame(1, $list[0]['checklist']['done']);
    }

    public function testUnknownFilterFieldIsRejected(): void
    {
        $result = $this->api->get('/notes', ['note_type' => 'nonsense']);
        $this->assertSame(400, $result['status']);
    }

    public function testWrongMethodOnAKnownPathIs405(): void
    {
        $this->assertSame(405, $this->api->post('/notes/counts')['status']);
    }
}
