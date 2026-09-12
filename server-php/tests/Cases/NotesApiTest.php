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

    /**
     * Copying a note someone shared with you lands it somewhere you can reach.
     *
     * A shared note usually sits in the owner's notebook, and filing into a
     * notebook needs edit rights on it — so carrying the notebook id over
     * answered 404 naming a notebook the copier had never been told about and
     * could do nothing with. The copy is theirs: it goes unfiled instead.
     */
    public function testDuplicatingASharedNoteDoesNotDemandTheOwnersNotebook(): void
    {
        $bob = new ApiClient(Support::user('b'));

        $notebook = $this->api->post('/notebooks', ['name' => 'Alice private'])['body']['data'];
        $note = $this->api->post('/notes', [
            'title' => 'Playbook',
            'document' => Support::doc('the original'),
            'notebook_id' => $notebook['id'],
        ])['body']['data'];

        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id, :n, :u, \'viewer\', :i)',
            ['id' => Uuid::v4(), 'n' => $note['id'], 'u' => Support::user('b')->userId, 'i' => Support::user('a')->userId],
        );

        $result = $bob->post('/notes/' . $note['id'] . '/duplicate');

        $this->assertSame(201, $result['status']);
        $this->assertNull(
            $result['body']['data']['notebook_id'],
            'unfiled, because the only other place is a notebook that is not theirs',
        );
    }

    /**
     * A save carrying a version the note has moved past is refused.
     *
     * This exercises the read check, which is what a sequential test can
     * reach. The genuine race — both requests reading version 1 before either
     * commits — needs two processes interleaved inside one transaction window,
     * which this suite cannot stage; the predicate on the UPDATE is what
     * covers it, so that the loser matches no row instead of quietly replacing
     * a document the winner had just stored.
     */
    public function testAStaleSaveIsRefusedEvenWhenItPassedTheReadCheck(): void
    {
        $note = $this->api->post('/notes', [
            'title' => 'Contested',
            'document' => Support::doc('original'),
        ])['body']['data'];

        $first = $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('the winner'),
            'version' => $note['version'],
        ]);
        $this->assertSame(200, $first['status']);

        // The same version the loser read a moment before the winner committed.
        $second = $this->api->patch('/notes/' . $note['id'], [
            'document' => Support::doc('the loser'),
            'version' => $note['version'],
        ]);

        $this->assertSame(409, $second['status']);
        $this->assertSame('VERSION_CONFLICT', $second['body']['error']['code']);
        $this->assertContainsString(
            'the winner',
            $this->api->get('/notes/' . $note['id'])['body']['data']['excerpt'],
        );
    }

    /**
     * Every sort pages through all of its notes, once each.
     *
     * The keyset used to compare `(updated_at, id) <` whatever the sort was.
     * That is right for the default and wrong for every other: `updated_asc`
     * walks the other way, so page two handed back page one and then jumped
     * past everything between; `created_*` and `title_*` compared a column the
     * rows were not ordered by at all. Neither failed — they returned a page
     * with notes silently missing from it.
     */
    public function testEverySortPagesWithoutRepeatingOrSkipping(): void
    {
        $titles = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
        foreach ($titles as $title) {
            $this->api->post('/notes', ['title' => $title, 'document' => Support::doc($title)]);
        }

        foreach (['updated_desc', 'updated_asc', 'created_desc', 'created_asc', 'title_asc', 'title_desc'] as $sort) {
            $seen = [];
            $cursor = null;

            // Two at a time, so paging is exercised rather than skipped.
            for ($page = 0; $page < 10; $page++) {
                $query = ['sort' => $sort, 'limit' => 2] + ($cursor === null ? [] : ['cursor' => $cursor]);
                $result = $this->api->get('/notes', $query);

                foreach ($result['body']['data'] as $note) {
                    $seen[] = $note['title'];
                }

                $cursor = $result['body']['meta']['next_cursor'] ?? null;
                if ($cursor === null) {
                    break;
                }
            }

            sort($seen);
            $expected = $titles;
            sort($expected);
            $this->assertSame($expected, $seen, $sort . ' returned each note exactly once');
        }
    }

    /** A cursor from one sort is not read as a cursor for another. */
    public function testReSortingStartsAgainRatherThanComparingApplesToOranges(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $this->api->post('/notes', ['title' => $title, 'document' => Support::doc($title)]);
        }

        $byTitle = $this->api->get('/notes', ['sort' => 'title_asc', 'limit' => 1]);
        $cursor = $byTitle['body']['meta']['next_cursor'];
        $this->assertNotNull($cursor);

        // That cursor carries a title. Handing it to a sort that compares
        // timestamps would compare a title against a timestamptz — a 500 at
        // best, a wrong page at worst.
        $byDate = $this->api->get('/notes', [
            'sort' => 'updated_desc',
            'limit' => 10,
            'cursor' => $cursor,
        ]);

        $this->assertSame(200, $byDate['status']);
        $this->assertCount(3, $byDate['body']['data'], 'the list starts again from the top');
    }

    /**
     * The note that used to become unwritable, saved and then saved again.
     *
     * The bound lives in NoteDocument; this is the proof that it is enough.
     * `search_vector` is GENERATED, so if the text were too long the failure
     * would not be this request alone — every later write to the row would
     * fail too, which is why the second save is here.
     */
    public function testAVeryLongNoteSavesAndStaysWritable(): void
    {
        // Few nodes, enormous text. A long document is capped by the node
        // budget long before it reaches the tsvector limit; what gets past
        // both is a handful of paragraphs each holding a wall of distinct
        // words — a pasted export, a log, a transcript. Identical words would
        // collapse into one lexeme and never reach the limit however many
        // times they repeat, so these are all different.
        $wall = '';
        for ($i = 0; $i < 30000; $i++) {
            $wall .= 'ledger' . $i . ' ' . md5((string) $i) . ' ';
        }

        $paragraphs = [];
        for ($p = 0; $p < 3; $p++) {
            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $wall]],
            ];
        }

        $created = $this->api->post('/notes', [
            'title' => 'Every transaction this year',
            'document' => ['type' => 'doc', 'content' => $paragraphs],
        ]);
        $this->assertSame(201, $created['status']);

        $note = $created['body']['data'];
        $renamed = $this->api->patch('/notes/' . $note['id'], ['title' => 'Renamed after the fact']);
        $this->assertSame(200, $renamed['status']);
        $this->assertSame(204, $this->api->delete('/notes/' . $note['id'])['status']);
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
