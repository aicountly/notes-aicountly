<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Smart folders over the real router.
 *
 * A smart folder is a saved query wearing a folder's clothes, so the tests are
 * mostly about the two ways that illusion can break: a folder that *moves* a
 * note (it must not), and a rule that reaches notes its author cannot open (it
 * must not, however the rule is written).
 */
final class SmartFoldersTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'SmartFolders';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    /** @param array<string, mixed> $extra */
    private function note(ApiClient $api, string $text, array $extra = []): array
    {
        return $api->post('/notes', ['document' => Support::doc($text)] + $extra)['body']['data'];
    }

    /** @param array<int, array<string, mixed>> $conditions */
    private function folder(ApiClient $api, string $name, array $conditions, string $match = 'all'): array
    {
        return $api->post('/smart-folders', [
            'name' => $name,
            'rules' => ['match' => $match, 'conditions' => $conditions],
        ])['body']['data'];
    }

    /** @return array<int, string> The display titles a folder lists, in order. */
    private function titles(ApiClient $api, string $folderId, array $query = []): array
    {
        $result = $api->get('/smart-folders/' . $folderId . '/notes', $query);

        return array_map(
            static fn (array $note): string => $note['display_title'],
            $result['body']['data'] ?? [],
        );
    }

    // -- Creating and listing ------------------------------------------------

    public function testCreatesAFolderAndListsItWithALiveCount(): void
    {
        $created = $this->alice->post('/smart-folders', [
            'name' => 'GST work',
            'icon' => 'receipt',
            'color' => 'sage',
            'rules' => ['match' => 'all', 'conditions' => [
                ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
            ]],
        ]);

        $this->assertSame(201, $created['status']);
        $folder = $created['body']['data'];
        $this->assertSame('GST work', $folder['name']);
        $this->assertSame('receipt', $folder['icon']);
        $this->assertSame('sage', $folder['color']);
        $this->assertSame(0, $folder['position']);
        $this->assertSame(0, $folder['note_count'], 'nothing matches yet');
        $this->assertFalse($folder['note_count_is_capped']);
        $this->assertTrue($folder['rules_valid']);

        // The count is live: it follows the library, not the folder.
        $this->note($this->alice, 'quarterly return', ['tags' => ['GST']]);
        $this->note($this->alice, 'unrelated', ['tags' => ['audit']]);

        $listed = $this->alice->get('/smart-folders');
        $this->assertSame(200, $listed['status']);
        $this->assertCount(1, $listed['body']['data']);
        $this->assertSame(1, $listed['body']['data'][0]['note_count']);
    }

    /**
     * The badge is bounded, and says when it is.
     *
     * The cap is configuration precisely so it can be exercised: at its default
     * of 500 this test would have to write 500 notes to prove the scan stops,
     * which is the cost the cap exists to avoid.
     */
    public function testTheMatchCountStopsAtTheCap(): void
    {
        putenv('NOTES_SMART_FOLDER_COUNT_CAP=2');

        try {
            foreach (['one', 'two', 'three'] as $title) {
                $this->note($this->alice, $title, ['tags' => ['gst']]);
            }
            $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

            $folder = $this->alice->get('/smart-folders')['body']['data'][0];
            $this->assertSame(2, $folder['note_count']);
            $this->assertTrue($folder['note_count_is_capped'], 'the client needs to know to render "2+"');

            // The folder itself is not capped — only the badge is.
            $this->assertCount(3, $this->titles($this->alice, $folder['id']));
        } finally {
            putenv('NOTES_SMART_FOLDER_COUNT_CAP');
        }
    }

    public function testANamelessFolderIsRefused(): void
    {
        $result = $this->alice->post('/smart-folders', ['name' => '   ']);

        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
        $this->assertCount(0, $this->alice->get('/smart-folders')['body']['data']);
    }

    // -- The rules decide which notes ---------------------------------------

    public function testTheRulesSelectTheNotes(): void
    {
        $this->note($this->alice, 'gst filing', ['tags' => ['gst'], 'is_favourite' => true]);
        $this->note($this->alice, 'gst notes', ['tags' => ['gst']]);
        $this->note($this->alice, 'staff list', ['tags' => ['hr'], 'is_favourite' => true]);

        $all = $this->folder($this->alice, 'Starred GST', [
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
            ['field' => 'is_favourite', 'operator' => 'is', 'value' => true],
        ]);
        $this->assertSame(['gst filing'], $this->titles($this->alice, $all['id']));

        $any = $this->folder($this->alice, 'GST or starred', [
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
            ['field' => 'is_favourite', 'operator' => 'is', 'value' => true],
        ], match: 'any');
        $this->assertCount(3, $this->titles($this->alice, $any['id']));
    }

    public function testAFolderNeverMovesANote(): void
    {
        $notebook = $this->alice->post('/notebooks', ['name' => 'Clients'])['body']['data'];
        $note = $this->note($this->alice, 'client meeting', [
            'notebook_id' => $notebook['id'],
            'tags' => ['gst'],
        ]);

        $byTag = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);
        $byBook = $this->folder($this->alice, 'Clients', [
            ['field' => 'notebook', 'operator' => 'is', 'value' => $notebook['id']],
        ]);

        // The same note is in both folders at once, and still filed where it was.
        $this->assertSame(['client meeting'], $this->titles($this->alice, $byTag['id']));
        $this->assertSame(['client meeting'], $this->titles($this->alice, $byBook['id']));

        $stored = $this->alice->get('/notes/' . $note['id'])['body']['data'];
        $this->assertSame($notebook['id'], $stored['notebook_id'], 'a smart folder does not refile a note');

        // Deleting the folder takes nothing with it.
        $this->assertSame(204, $this->alice->delete('/smart-folders/' . $byTag['id'])['status']);
        $this->assertSame(200, $this->alice->get('/notes/' . $note['id'])['status']);
        $this->assertCount(1, $this->alice->get('/smart-folders')['body']['data']);
    }

    public function testARuleAboutArchivedNotesFindsThem(): void
    {
        $filed = $this->note($this->alice, 'last year', ['tags' => ['gst']]);
        $this->note($this->alice, 'this year', ['tags' => ['gst']]);
        $this->alice->post('/notes/' . $filed['id'] . '/archive');

        $folder = $this->folder($this->alice, 'Archived GST', [
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
            ['field' => 'is_archived', 'operator' => 'is', 'value' => true],
        ]);

        // The default list hides archived notes, so a folder that asks for them
        // has to widen the scope itself or it is permanently empty.
        $this->assertSame(['last year'], $this->titles($this->alice, $folder['id']));
        $this->assertSame(1, $this->alice->get('/smart-folders')['body']['data'][0]['note_count']);
    }

    public function testATrashedNoteLeavesEveryFolder(): void
    {
        $note = $this->note($this->alice, 'quarterly return', ['tags' => ['gst']]);
        $folder = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

        $this->alice->delete('/notes/' . $note['id']);

        // Not even the widest scope reaches Trash: a folder is a view over the
        // library, and Trash is the place things go to stop being in it.
        $this->assertCount(0, $this->titles($this->alice, $folder['id']));
        $this->assertCount(0, $this->titles($this->alice, $folder['id'], ['scope' => 'all']));
        $this->assertSame(0, $this->alice->get('/smart-folders')['body']['data'][0]['note_count']);

        $this->alice->post('/notes/' . $note['id'] . '/restore');
        $this->assertCount(1, $this->titles($this->alice, $folder['id']));
    }

    public function testTheWholeFilterVocabularyComposes(): void
    {
        $this->note($this->alice, 'gst invoice queries for the client', [
            'title' => 'Invoice queries',
            'tags' => ['gst'],
            'note_type' => 'document',
        ]);
        $this->note($this->alice, 'gst something else entirely', ['title' => 'Other', 'tags' => ['gst']]);

        // Text, tag, type and a date window in one rule tree — the count query
        // and the list query build the same clauses, so both are exercised here.
        $folder = $this->folder($this->alice, 'Recent invoice queries', [
            ['field' => 'text', 'operator' => 'contains', 'value' => 'invoice queries'],
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
            ['field' => 'note_type', 'operator' => 'is', 'value' => 'document'],
            ['field' => 'has_attachment', 'operator' => 'is', 'value' => false],
            ['field' => 'updated_at', 'operator' => 'within_days', 'value' => 7],
        ]);

        $this->assertSame(['Invoice queries'], $this->titles($this->alice, $folder['id']));
        $this->assertSame(1, $this->alice->get('/smart-folders')['body']['data'][0]['note_count']);
    }

    // -- Validation at write time -------------------------------------------

    public function testAnUnknownFieldOrOperatorIsRejectedAtWriteTime(): void
    {
        $badField = $this->alice->post('/smart-folders', [
            'name' => 'Nonsense',
            'rules' => ['match' => 'all', 'conditions' => [
                ['field' => 'secret_column', 'operator' => 'is', 'value' => 'x'],
            ]],
        ]);
        $this->assertSame(422, $badField['status']);
        $this->assertSame('VALIDATION_FAILED', $badField['body']['error']['code']);
        $this->assertContainsString('secret_column', $badField['body']['error']['details']['fields']['rules']);

        $badOperator = $this->alice->post('/smart-folders', [
            'name' => 'Nonsense',
            'rules' => ['match' => 'all', 'conditions' => [
                ['field' => 'note_type', 'operator' => 'contains', 'value' => 'document'],
            ]],
        ]);
        $this->assertSame(422, $badOperator['status']);

        $badMatch = $this->alice->post('/smart-folders', [
            'name' => 'Nonsense',
            'rules' => ['match' => 'sometimes', 'conditions' => []],
        ]);
        $this->assertSame(422, $badMatch['status']);

        // Nothing broken was stored, so no later read can trip over it.
        $this->assertCount(0, $this->alice->get('/smart-folders')['body']['data']);
    }

    public function testAnEditCannotBreakAStoredFolder(): void
    {
        $folder = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);
        $this->note($this->alice, 'quarterly return', ['tags' => ['gst']]);

        $broken = $this->alice->patch('/smart-folders/' . $folder['id'], [
            'rules' => ['match' => 'all', 'conditions' => [
                ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
                ['field' => 'made_up', 'operator' => 'is', 'value' => 1],
            ]],
        ]);

        $this->assertSame(422, $broken['status']);
        // The refused edit left the working rules in place.
        $this->assertSame(['quarterly return'], $this->titles($this->alice, $folder['id']));
        $this->assertSame(1, $this->alice->get('/smart-folders')['body']['data'][0]['note_count']);
    }

    public function testAMalformedRuleValueIsRefused(): void
    {
        $notARule = $this->alice->post('/smart-folders', [
            'name' => 'Nonsense',
            'rules' => ['match' => 'all', 'conditions' => ['tag']],
        ]);
        $this->assertSame(422, $notARule['status']);

        $notAnObject = $this->alice->post('/smart-folders', ['name' => 'Nonsense', 'rules' => 'tag:gst']);
        $this->assertSame(422, $notAnObject['status']);

        // A notebook filter needs an id, not a name — otherwise the value would
        // reach a uuid column and Postgres would answer with a 500.
        $notAnId = $this->alice->post('/smart-folders', [
            'name' => 'Nonsense',
            'rules' => ['match' => 'all', 'conditions' => [
                ['field' => 'notebook', 'operator' => 'is', 'value' => 'Clients'],
            ]],
        ]);
        $this->assertSame(422, $notAnId['status']);
    }

    // -- Pagination and sort -------------------------------------------------

    public function testPaginatesAndSortsLikeTheNotesList(): void
    {
        foreach (['alpha', 'bravo', 'charlie'] as $title) {
            // Titled on purpose: `title_asc` sorts on the stored title, and
            // three untitled notes would tie and fall back to their ids.
            $this->note($this->alice, $title, ['title' => $title, 'tags' => ['gst']]);
        }
        $folder = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

        $first = $this->alice->get('/smart-folders/' . $folder['id'] . '/notes', ['limit' => 2]);
        $this->assertSame(200, $first['status']);
        $this->assertCount(2, $first['body']['data']);
        $this->assertTrue($first['body']['meta']['has_more']);
        $this->assertNotNull($first['body']['meta']['next_cursor']);

        $second = $this->alice->get('/smart-folders/' . $folder['id'] . '/notes', [
            'limit' => 2,
            'cursor' => $first['body']['meta']['next_cursor'],
        ]);
        $this->assertCount(1, $second['body']['data']);
        $this->assertFalse($second['body']['meta']['has_more']);

        $this->assertSame(
            ['alpha', 'bravo', 'charlie'],
            $this->titles($this->alice, $folder['id'], ['sort' => 'title_asc']),
        );
        $this->assertSame(
            ['charlie', 'bravo', 'alpha'],
            $this->titles($this->alice, $folder['id'], ['sort' => 'title_desc']),
        );
    }

    // -- Rename, restyle, reorder -------------------------------------------

    public function testRenamesRestylesAndReorders(): void
    {
        $first = $this->folder($this->alice, 'First', []);
        $second = $this->folder($this->alice, 'Second', []);
        $third = $this->folder($this->alice, 'Third', []);

        $renamed = $this->alice->patch('/smart-folders/' . $first['id'], [
            'name' => 'Renamed',
            'icon' => 'star',
        ])['body']['data'];
        $this->assertSame('Renamed', $renamed['name']);
        $this->assertSame('star', $renamed['icon']);
        $this->assertNull($renamed['color'], 'a rename must not invent a colour');

        $recoloured = $this->alice->patch('/smart-folders/' . $first['id'], ['color' => 'sky'])['body']['data'];
        $this->assertSame('sky', $recoloured['color']);
        $this->assertSame('Renamed', $recoloured['name'], 'a recolour must not touch the name');
        $this->assertSame('star', $recoloured['icon']);

        $cleared = $this->alice->patch('/smart-folders/' . $first['id'], ['icon' => null])['body']['data'];
        $this->assertNull($cleared['icon'], 'an explicit null is how an icon is cleared');

        // Drag the first folder to the end: the whole list is re-sequenced.
        $moved = $this->alice->patch('/smart-folders/' . $first['id'], ['position' => 2]);
        $this->assertSame(200, $moved['status']);
        $this->assertSame(2, $moved['body']['data']['position']);

        $order = array_map(
            static fn (array $folder): string => $folder['id'],
            $this->alice->get('/smart-folders')['body']['data'],
        );
        $this->assertSame([$second['id'], $third['id'], $first['id']], $order);
    }

    /**
     * The other half of the bound on `GET /smart-folders`.
     *
     * That endpoint costs one capped count per folder, so the number of folders
     * is what decides the size of the page — hence a ceiling, and a delete that
     * genuinely makes room.
     */
    public function testThereIsACeilingOnHowManyFoldersOnePersonKeeps(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->folder($this->alice, 'Folder ' . $i, [])['id'];
        }

        $refused = $this->alice->post('/smart-folders', ['name' => 'One too many']);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('SMART_FOLDER_LIMIT', $refused['body']['error']['code']);

        $this->alice->delete('/smart-folders/' . $ids[0]);
        $this->assertSame(201, $this->alice->post('/smart-folders', ['name' => 'Room again'])['status']);
    }

    public function testDeletingAFolderRemovesItFromTheList(): void
    {
        $folder = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

        $this->assertSame(204, $this->alice->delete('/smart-folders/' . $folder['id'])['status']);
        $this->assertCount(0, $this->alice->get('/smart-folders')['body']['data']);

        // A deleted folder is gone for every endpoint, not just the list.
        $this->assertSame(404, $this->alice->get('/smart-folders/' . $folder['id'] . '/notes')['status']);
        $this->assertSame(404, $this->alice->patch('/smart-folders/' . $folder['id'], ['name' => 'back'])['status']);
        $this->assertSame(404, $this->alice->delete('/smart-folders/' . $folder['id'])['status']);
    }

    public function testAMalformedFolderIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/smart-folders/not-a-uuid/notes')['status']);
        $this->assertSame(404, $this->alice->patch('/smart-folders/' . Uuid::v4(), ['name' => 'x'])['status']);
    }

    // -- Someone else's folders and someone else's notes ---------------------

    public function testAnotherUsersFoldersAreOutOfReach(): void
    {
        $this->note($this->alice, 'quarterly return', ['tags' => ['gst']]);
        $folder = $this->folder($this->alice, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

        // Bob's list is his own, and shows nothing of Alice's.
        $this->assertCount(0, $this->bob->get('/smart-folders')['body']['data']);

        // 404 rather than 403: a folder id must not confirm that a folder exists.
        $this->assertSame(404, $this->bob->get('/smart-folders/' . $folder['id'] . '/notes')['status']);
        $this->assertSame(404, $this->bob->patch('/smart-folders/' . $folder['id'], ['name' => 'mine now'])['status']);
        $this->assertSame(404, $this->bob->delete('/smart-folders/' . $folder['id'])['status']);

        // Nothing Bob tried touched Alice's folder.
        $mine = $this->alice->get('/smart-folders')['body']['data'][0];
        $this->assertSame('GST', $mine['name']);
        $this->assertSame(1, $mine['note_count']);
    }

    /**
     * The rule that matters: a folder is not a way around the access gate.
     *
     * Both filters below name something real — Alice's notebook id, and a tag
     * slug her notes carry — and neither is owned by Bob. Saving them is
     * allowed on purpose (refusing would tell Bob which ids exist); what must
     * never happen is either one returning Alice's notes.
     */
    public function testRulesNamingAnotherUsersNotebookOrTagMatchNothing(): void
    {
        $notebook = $this->alice->post('/notebooks', ['name' => 'Clients'])['body']['data'];
        $this->note($this->alice, 'alice private working paper', [
            'notebook_id' => $notebook['id'],
            'tags' => ['gst'],
        ]);
        $this->note($this->bob, 'bob own note', ['tags' => ['gst']]);

        $stolenNotebook = $this->folder($this->bob, 'Their notebook', [
            ['field' => 'notebook', 'operator' => 'is', 'value' => $notebook['id']],
        ]);
        $stolenTag = $this->folder($this->bob, 'Their tag', [
            ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
        ]);
        $stolenOwner = $this->folder($this->bob, 'Their notes', [
            ['field' => 'owner', 'operator' => 'is', 'value' => 'user-a'],
        ]);

        $this->assertCount(0, $this->titles($this->bob, $stolenNotebook['id']));
        $this->assertCount(0, $this->titles($this->bob, $stolenOwner['id']));
        // The tag slug is shared, so this one only returns Bob's own note — the
        // access CTE is what stops it from returning Alice's as well.
        $this->assertSame(['bob own note'], $this->titles($this->bob, $stolenTag['id']));

        // And the counts in his sidebar tell him nothing either.
        $counts = [];
        foreach ($this->bob->get('/smart-folders')['body']['data'] as $folder) {
            $counts[$folder['name']] = $folder['note_count'];
        }
        $this->assertSame(0, $counts['Their notebook']);
        $this->assertSame(0, $counts['Their notes']);
        $this->assertSame(1, $counts['Their tag']);

        // Alice's own folder over the same rules still finds her note, so the
        // filter works and it is access, not the rule, doing the hiding.
        $hers = $this->folder($this->alice, 'Clients', [
            ['field' => 'notebook', 'operator' => 'is', 'value' => $notebook['id']],
        ]);
        $this->assertSame(['alice private working paper'], $this->titles($this->alice, $hers['id']));
    }

    public function testASharedNoteReachesTheFolderOfThePersonItWasSharedWith(): void
    {
        $note = $this->note($this->alice, 'shared working paper', ['tags' => ['gst']]);
        $this->alice->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'viewer']);

        $folder = $this->folder($this->bob, 'GST', [['field' => 'tag', 'operator' => 'is', 'value' => 'gst']]);

        // A grant is the only thing that changes what a folder can see, and it
        // changes it the same way everywhere else in the API does.
        $this->assertSame(['shared working paper'], $this->titles($this->bob, $folder['id']));
        $this->assertSame(1, $this->bob->get('/smart-folders')['body']['data'][0]['note_count']);
    }
}
