<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Notebooks\NotebookService;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Notebooks: the tree, and the ways a tree stops being one.
 *
 * The cases that matter here are the structural ones. A cycle or a runaway
 * depth is not a cosmetic bug in this schema — access, filtering and the
 * sidebar all walk `parent_id` recursively — so the refusals are tested from
 * the outside, over the real router, exactly as a client would meet them.
 */
final class NotebooksTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Notebooks';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    /** @return array<string, mixed> */
    private function notebook(ApiClient $api, string $name, ?string $parentId = null): array
    {
        $result = $api->post('/notebooks', ['name' => $name, 'parent_id' => $parentId]);
        $this->assertSame(201, $result['status'], 'creating "' . $name . '"');

        return $result['body']['data'];
    }

    private function shareNotebook(string $notebookId, string $userId, string $role): void
    {
        Connection::execute(
            'INSERT INTO notebook_members (id, notebook_id, user_id, role, invited_by)
             VALUES (:id, :nb, :user, :role, \'user-a\')',
            ['id' => Uuid::v4(), 'nb' => $notebookId, 'user' => $userId, 'role' => $role],
        );
    }

    // -- The tree ----------------------------------------------------------

    public function testCreatesANotebookAndReadsItBack(): void
    {
        $created = $this->alice->post('/notebooks', [
            'name' => 'Clients',
            'description' => 'Everything client-facing',
            'icon' => '📁',
            'color' => 'sage',
        ]);

        $this->assertSame(201, $created['status']);
        $notebook = $created['body']['data'];
        $this->assertTrue(Uuid::isValid($notebook['id']));
        $this->assertSame('Clients', $notebook['name']);
        $this->assertSame(0, $notebook['depth']);
        $this->assertNull($notebook['parent_id']);
        $this->assertSame('sage', $notebook['color']);
        $this->assertSame('owner', $notebook['role']);
        $this->assertTrue($notebook['capabilities']['manage_members']);

        $fetched = $this->alice->get('/notebooks/' . $notebook['id']);
        $this->assertSame(200, $fetched['status']);
        $this->assertSame('Everything client-facing', $fetched['body']['data']['description']);
    }

    public function testTheTreeComesBackNestedWithNoteCounts(): void
    {
        $clients = $this->notebook($this->alice, 'Clients');
        $abc = $this->notebook($this->alice, 'ABC Pvt Ltd', $clients['id']);
        $this->notebook($this->alice, 'Personal');

        $this->alice->post('/notes', ['notebook_id' => $clients['id'], 'document' => Support::doc('parent note')]);
        $this->alice->post('/notes', ['notebook_id' => $abc['id'], 'document' => Support::doc('child note')]);
        $this->alice->post('/notes', ['notebook_id' => $abc['id'], 'document' => Support::doc('second child note')]);

        $tree = $this->alice->get('/notebooks')['body']['data'];
        $this->assertCount(2, $tree, 'two roots');

        $clientsNode = $tree[0]['name'] === 'Clients' ? $tree[0] : $tree[1];
        $this->assertCount(1, $clientsNode['children']);
        $this->assertSame('ABC Pvt Ltd', $clientsNode['children'][0]['name']);
        $this->assertSame(1, $clientsNode['note_count'], 'only the notes filed directly here');
        $this->assertSame(2, $clientsNode['children'][0]['note_count']);
        $this->assertSame(3, $clientsNode['total_note_count'], 'the subtree rolls up');
    }

    public function testNoteCountsIgnoreTrashedAndArchivedNotes(): void
    {
        $notebook = $this->notebook($this->alice, 'Inbox');
        $keep = $this->alice->post('/notes', ['notebook_id' => $notebook['id'], 'document' => Support::doc('keep')])['body']['data'];
        $archived = $this->alice->post('/notes', ['notebook_id' => $notebook['id'], 'document' => Support::doc('archive me')])['body']['data'];
        $trashed = $this->alice->post('/notes', ['notebook_id' => $notebook['id'], 'document' => Support::doc('bin me')])['body']['data'];

        $this->alice->post('/notes/' . $archived['id'] . '/archive');
        $this->alice->delete('/notes/' . $trashed['id']);

        $tree = $this->alice->get('/notebooks')['body']['data'];
        $this->assertSame(1, $tree[0]['note_count']);
        $this->assertNotNull($keep['id']);
    }

    public function testASharedSubNotebookBecomesARootForTheRecipient(): void
    {
        $projects = $this->notebook($this->alice, 'Projects');
        $q4 = $this->notebook($this->alice, 'Q4', $projects['id']);

        // Sharing a sub-notebook grants its descendants, never its ancestors,
        // so Bob's tree has to start at Q4 rather than dangle off a parent he
        // is not allowed to know about.
        $this->shareNotebook($q4['id'], 'user-b', 'viewer');

        $tree = $this->bob->get('/notebooks')['body']['data'];
        $this->assertCount(1, $tree);
        $this->assertSame('Q4', $tree[0]['name']);
        $this->assertSame($projects['id'], $tree[0]['parent_id'], 'the real parent is still reported');
        $this->assertSame('viewer', $tree[0]['role']);
        $this->assertSame(404, $this->bob->get('/notebooks/' . $projects['id'])['status']);
    }

    public function testArchivingHidesTheWholeBranch(): void
    {
        $old = $this->notebook($this->alice, 'Old work');
        $inside = $this->notebook($this->alice, '2019', $old['id']);
        $this->notebook($this->alice, 'Current');

        $this->assertSame(200, $this->alice->patch('/notebooks/' . $old['id'], ['is_archived' => true])['status']);

        $tree = $this->alice->get('/notebooks')['body']['data'];
        $this->assertCount(1, $tree);
        $this->assertSame('Current', $tree[0]['name'], 'the archived child must not resurface as a root');

        $withArchived = $this->alice->get('/notebooks', ['include_archived' => '1'])['body']['data'];
        $this->assertCount(2, $withArchived);

        $unarchived = $this->alice->patch('/notebooks/' . $old['id'], ['is_archived' => false]);
        $this->assertFalse($unarchived['body']['data']['is_archived']);
        $this->assertCount(2, $this->alice->get('/notebooks')['body']['data']);
        $this->assertNotNull($inside['id']);
    }

    // -- Cycles ------------------------------------------------------------

    public function testANotebookCannotBecomeItsOwnParent(): void
    {
        $notebook = $this->notebook($this->alice, 'Recursion');

        $result = $this->alice->post('/notebooks/' . $notebook['id'] . '/move', ['parent_id' => $notebook['id']]);
        $this->assertSame(409, $result['status']);
        $this->assertSame('NOTEBOOK_CYCLE', $result['body']['error']['code']);

        $stored = Connection::selectOne('SELECT parent_id FROM notebooks WHERE id = :id', ['id' => $notebook['id']]);
        $this->assertNull($stored['parent_id'], 'the refusal must not have been stored');
    }

    public function testANotebookCannotBeMovedUnderItsOwnDescendant(): void
    {
        $top = $this->notebook($this->alice, 'Top');
        $middle = $this->notebook($this->alice, 'Middle', $top['id']);
        $bottom = $this->notebook($this->alice, 'Bottom', $middle['id']);

        $result = $this->alice->post('/notebooks/' . $top['id'] . '/move', ['parent_id' => $bottom['id']]);
        $this->assertSame(409, $result['status']);
        $this->assertSame('NOTEBOOK_CYCLE', $result['body']['error']['code']);

        // And the tree still reads as a tree.
        $tree = $this->alice->get('/notebooks')['body']['data'];
        $this->assertCount(1, $tree);
        $this->assertSame('Top', $tree[0]['name']);
        $this->assertSame('Bottom', $tree[0]['children'][0]['children'][0]['name']);
    }

    public function testMoveRequiresAnExplicitParent(): void
    {
        $notebook = $this->notebook($this->alice, 'Somewhere');

        // Omitting the key must not be read as "move it to the top level".
        $result = $this->alice->post('/notebooks/' . $notebook['id'] . '/move', ['position' => 0]);
        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
    }

    // -- Depth -------------------------------------------------------------

    public function testNestingIsCappedAndTheSubtreeMovesWithIt(): void
    {
        $parentId = null;
        $ids = [];
        for ($level = 1; $level <= NotebookService::MAX_DEPTH; $level++) {
            $node = $this->notebook($this->alice, 'Level ' . $level, $parentId);
            $this->assertSame($level - 1, $node['depth']);
            $ids[] = $node['id'];
            $parentId = $node['id'];
        }

        $tooDeep = $this->alice->post('/notebooks', ['name' => 'One too many', 'parent_id' => $parentId]);
        $this->assertSame(409, $tooDeep['status']);
        $this->assertSame('NOTEBOOK_TOO_DEEP', $tooDeep['body']['error']['code']);

        // A move is measured on the whole subtree, not just the node moved.
        $branch = $this->notebook($this->alice, 'Branch');
        $this->notebook($this->alice, 'Twig', $branch['id']);

        $refused = $this->alice->post('/notebooks/' . $branch['id'] . '/move', [
            'parent_id' => $ids[NotebookService::MAX_DEPTH - 2],
        ]);
        $this->assertSame(409, $refused['status'], 'a two-level branch cannot land on the last level');
        $this->assertSame('NOTEBOOK_TOO_DEEP', $refused['body']['error']['code']);
    }

    public function testMovingASubtreeRewritesItsStoredDepth(): void
    {
        $home = $this->notebook($this->alice, 'Home');
        $work = $this->notebook($this->alice, 'Work');
        $branch = $this->notebook($this->alice, 'Branch');
        $leaf = $this->notebook($this->alice, 'Leaf', $branch['id']);
        $this->assertNotNull($home['id']);

        $moved = $this->alice->post('/notebooks/' . $branch['id'] . '/move', ['parent_id' => $work['id']]);
        $this->assertSame(200, $moved['status']);
        $this->assertSame(1, $moved['body']['data']['depth']);
        $this->assertSame(2, $moved['body']['data']['children'][0]['depth'], 'the leaf moved down with it');

        $stored = Connection::selectOne('SELECT depth FROM notebooks WHERE id = :id', ['id' => $leaf['id']]);
        $this->assertSame(2, (int) $stored['depth']);

        // Back out to the top level, which is what `parent_id: null` means.
        $toRoot = $this->alice->post('/notebooks/' . $branch['id'] . '/move', ['parent_id' => null]);
        $this->assertSame(200, $toRoot['status']);
        $this->assertNull($toRoot['body']['data']['parent_id']);
        $this->assertSame(0, $toRoot['body']['data']['depth']);
        $this->assertSame(1, $toRoot['body']['data']['children'][0]['depth']);
    }

    // -- Names -------------------------------------------------------------

    public function testADuplicateNameIsARefusalNotAServerError(): void
    {
        $this->notebook($this->alice, 'Clients');

        $clash = $this->alice->post('/notebooks', ['name' => 'clients']);
        $this->assertSame(409, $clash['status'], 'case-insensitive, and never a 500');
        $this->assertSame('NOTEBOOK_NAME_TAKEN', $clash['body']['error']['code']);

        // The same name under a different parent is fine — that is the point
        // of a tree.
        $parent = $this->notebook($this->alice, 'Archive');
        $this->assertSame(201, $this->alice->post('/notebooks', [
            'name' => 'Clients',
            'parent_id' => $parent['id'],
        ])['status']);

        // And it is another user's business what they call their notebooks.
        $this->assertSame(201, $this->bob->post('/notebooks', ['name' => 'Clients'])['status']);
    }

    public function testRenamingOntoASiblingsNameIsRefused(): void
    {
        $this->notebook($this->alice, 'Alpha');
        $beta = $this->notebook($this->alice, 'Beta');

        $result = $this->alice->patch('/notebooks/' . $beta['id'], ['name' => 'ALPHA']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('NOTEBOOK_NAME_TAKEN', $result['body']['error']['code']);

        // Re-saving a notebook under its own name is not a clash.
        $this->assertSame(200, $this->alice->patch('/notebooks/' . $beta['id'], ['name' => 'Beta'])['status']);
    }

    public function testMovingOntoATakenNameIsRefusedBeforeAnythingMoves(): void
    {
        $work = $this->notebook($this->alice, 'Work');
        $this->notebook($this->alice, 'Notes', $work['id']);
        $loose = $this->notebook($this->alice, 'Notes');

        $result = $this->alice->post('/notebooks/' . $loose['id'] . '/move', ['parent_id' => $work['id']]);
        $this->assertSame(409, $result['status']);
        $this->assertSame('NOTEBOOK_NAME_TAKEN', $result['body']['error']['code']);

        $stored = Connection::selectOne('SELECT parent_id FROM notebooks WHERE id = :id', ['id' => $loose['id']]);
        $this->assertNull($stored['parent_id']);
    }

    public function testANotebookNeedsAName(): void
    {
        $result = $this->alice->post('/notebooks', ['name' => '   ']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
    }

    // -- Reordering --------------------------------------------------------

    public function testPositionRenumbersTheSiblings(): void
    {
        $first = $this->notebook($this->alice, 'First');
        $second = $this->notebook($this->alice, 'Second');
        $third = $this->notebook($this->alice, 'Third');

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_column($this->alice->get('/notebooks')['body']['data'], 'name'),
        );

        $this->alice->patch('/notebooks/' . $third['id'], ['position' => 0]);

        $names = array_column($this->alice->get('/notebooks')['body']['data'], 'name');
        $this->assertSame(['Third', 'First', 'Second'], $names);

        $positions = array_column($this->alice->get('/notebooks')['body']['data'], 'position');
        $this->assertSame([0, 1, 2], $positions, 'siblings are re-sequenced, never left sharing a position');
        $this->assertNotNull($first['id']);
        $this->assertNotNull($second['id']);
    }

    // -- Delete ------------------------------------------------------------

    public function testDeletingANotebookMovesItsNotesAndChildrenToTheParent(): void
    {
        $work = $this->notebook($this->alice, 'Work');
        $clients = $this->notebook($this->alice, 'Clients', $work['id']);
        $abc = $this->notebook($this->alice, 'ABC', $clients['id']);

        $note = $this->alice->post('/notes', [
            'notebook_id' => $clients['id'],
            'title' => 'GST reconciliation',
            'document' => Support::doc('numbers'),
        ])['body']['data'];

        $deleted = $this->alice->delete('/notebooks/' . $clients['id']);
        $this->assertSame(200, $deleted['status']);
        $this->assertTrue($deleted['body']['data']['deleted']);
        $this->assertSame(1, $deleted['body']['data']['notes_moved']);
        $this->assertSame(1, $deleted['body']['data']['notebooks_moved']);
        $this->assertSame($work['id'], $deleted['body']['data']['moved_to_notebook_id']);
        $this->assertContainsString('moved to the parent notebook', $deleted['body']['data']['message']);

        // The note is still there, and is now filed one level up.
        $survivor = $this->alice->get('/notes/' . $note['id']);
        $this->assertSame(200, $survivor['status'], 'deleting a notebook must never destroy its notes');
        $this->assertSame($work['id'], $survivor['body']['data']['notebook_id']);

        $tree = $this->alice->get('/notebooks')['body']['data'];
        $this->assertCount(1, $tree);
        $this->assertSame('ABC', $tree[0]['children'][0]['name'], 'the sub-notebook moved up with the same rule');
        $this->assertSame(1, $tree[0]['children'][0]['depth'], 'and its depth was corrected');
        $this->assertSame(404, $this->alice->get('/notebooks/' . $clients['id'])['status']);
        $this->assertNotNull($abc['id']);
    }

    public function testDeletingARootNotebookUnfilesItsNotes(): void
    {
        $inbox = $this->notebook($this->alice, 'Inbox');
        $note = $this->alice->post('/notes', [
            'notebook_id' => $inbox['id'],
            'document' => Support::doc('somewhere to live'),
        ])['body']['data'];

        $deleted = $this->alice->delete('/notebooks/' . $inbox['id']);
        $this->assertNull($deleted['body']['data']['moved_to_notebook_id']);
        $this->assertContainsString('now unfiled', $deleted['body']['data']['message']);

        $survivor = $this->alice->get('/notes/' . $note['id'])['body']['data'];
        $this->assertNull($survivor['notebook_id']);
        $this->assertCount(1, $this->alice->get('/notes')['body']['data']);
    }

    public function testAChildKeepsAUsableNameWhenItsDestinationAlreadyHasOne(): void
    {
        $work = $this->notebook($this->alice, 'Work');
        $this->notebook($this->alice, 'Notes', $work['id']);
        $doomed = $this->notebook($this->alice, 'Doomed', $work['id']);
        $this->notebook($this->alice, 'Notes', $doomed['id']);

        // Two "Notes" cannot sit under "Work", and a delete that failed on
        // that would leave the user stuck.
        $this->assertSame(200, $this->alice->delete('/notebooks/' . $doomed['id'])['status']);

        $names = array_column($this->alice->get('/notebooks')['body']['data'][0]['children'], 'name');
        sort($names);
        $this->assertSame(['Notes', 'Notes (2)'], $names);
    }

    // -- Members -----------------------------------------------------------

    public function testTheOwnerSharesANotebookAndTheGrantCascades(): void
    {
        $projects = $this->notebook($this->alice, 'Projects');
        $q1 = $this->notebook($this->alice, 'Q1', $projects['id']);
        $note = $this->alice->post('/notes', [
            'notebook_id' => $q1['id'],
            'document' => Support::doc('inside a shared branch'),
        ])['body']['data'];

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'])['status']);

        $added = $this->alice->post('/notebooks/' . $projects['id'] . '/members', [
            'user_id' => 'user-b',
            'role' => 'editor',
        ]);
        $this->assertSame(201, $added['status']);
        $this->assertSame('editor', $added['body']['data']['role']);

        $this->assertSame(200, $this->bob->get('/notebooks/' . $q1['id'])['status'], 'the share cascades');
        $this->assertSame(200, $this->bob->get('/notes/' . $note['id'])['status']);

        $members = $this->alice->get('/notebooks/' . $projects['id'] . '/members')['body']['data'];
        $this->assertCount(2, $members, 'the owner is listed too');
        $this->assertSame('owner', $members[0]['role']);
        $this->assertSame('user-b', $members[1]['user_id']);

        // POST again is a role change, not a duplicate row.
        $changed = $this->alice->post('/notebooks/' . $projects['id'] . '/members', [
            'user_id' => 'user-b',
            'role' => 'viewer',
        ]);
        $this->assertSame(200, $changed['status']);
        $this->assertCount(2, $this->alice->get('/notebooks/' . $projects['id'] . '/members')['body']['data']);

        $removed = $this->alice->delete('/notebooks/' . $projects['id'] . '/members/user-b');
        $this->assertSame(204, $removed['status']);
        $this->assertSame(404, $this->bob->get('/notebooks/' . $q1['id'])['status'], 'revoking takes effect at once');
    }

    public function testOwnershipCannotBeHandedOverBySharing(): void
    {
        $notebook = $this->notebook($this->alice, 'Mine');

        $result = $this->alice->post('/notebooks/' . $notebook['id'] . '/members', [
            'user_id' => 'user-b',
            'role' => 'owner',
        ]);
        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
    }

    public function testRemovingSomeoneWhoIsNotAMemberIsANotFound(): void
    {
        $notebook = $this->notebook($this->alice, 'Mine');

        $result = $this->alice->delete('/notebooks/' . $notebook['id'] . '/members/user-c');
        $this->assertSame(404, $result['status']);
    }

    // -- Other people ------------------------------------------------------

    public function testAStrangerCannotReachAnyNotebookEndpoint(): void
    {
        $notebook = $this->notebook($this->alice, 'Private plans');
        $child = $this->notebook($this->alice, 'Inner', $notebook['id']);
        $bobsOwn = $this->notebook($this->bob, 'Bob only');

        // 404 everywhere, not 403: probing ids must not reveal what exists.
        $this->assertSame(404, $this->bob->get('/notebooks/' . $notebook['id'])['status']);
        $this->assertSame(404, $this->bob->patch('/notebooks/' . $notebook['id'], ['name' => 'hijacked'])['status']);
        $this->assertSame(404, $this->bob->delete('/notebooks/' . $notebook['id'])['status']);
        $this->assertSame(404, $this->bob->get('/notebooks/' . $notebook['id'] . '/members')['status']);
        $this->assertSame(404, $this->bob->post('/notebooks/' . $notebook['id'] . '/members', [
            'user_id' => 'user-b',
            'role' => 'editor',
        ])['status']);
        $this->assertSame(404, $this->bob->delete('/notebooks/' . $notebook['id'] . '/members/user-a')['status']);
        $this->assertSame(404, $this->bob->post('/notebooks/' . $child['id'] . '/move', ['parent_id' => null])['status']);

        // Nor can he file his own notebook inside one he cannot see.
        $this->assertSame(404, $this->bob->post('/notebooks/' . $bobsOwn['id'] . '/move', [
            'parent_id' => $notebook['id'],
        ])['status']);
        $this->assertSame(404, $this->bob->post('/notebooks', [
            'name' => 'Sneaky',
            'parent_id' => $notebook['id'],
        ])['status']);

        // And Alice's tree is not in his.
        $tree = $this->bob->get('/notebooks')['body']['data'];
        $this->assertCount(1, $tree);
        $this->assertSame('Bob only', $tree[0]['name']);

        $unchanged = $this->alice->get('/notebooks/' . $notebook['id'])['body']['data'];
        $this->assertSame('Private plans', $unchanged['name']);
    }

    public function testAnEditorMayRenameButNotRestructureOrReshare(): void
    {
        $notebook = $this->notebook($this->alice, 'Team');
        $this->shareNotebook($notebook['id'], 'user-b', 'editor');

        $renamed = $this->bob->patch('/notebooks/' . $notebook['id'], ['name' => 'Team (renamed)']);
        $this->assertSame(200, $renamed['status']);
        $this->assertFalse($renamed['body']['data']['capabilities']['delete']);

        // Moving and deleting change who can see the branch, so they stay with
        // the owner.
        $moved = $this->bob->post('/notebooks/' . $notebook['id'] . '/move', ['parent_id' => null]);
        $this->assertSame(403, $moved['status']);
        $this->assertSame('NOTEBOOK_ACCESS_DENIED', $moved['body']['error']['code']);

        $this->assertSame(403, $this->bob->delete('/notebooks/' . $notebook['id'])['status']);
        $this->assertSame(403, $this->bob->patch('/notebooks/' . $notebook['id'], ['is_archived' => true])['status']);
        $this->assertSame(403, $this->bob->post('/notebooks/' . $notebook['id'] . '/members', [
            'user_id' => 'user-c',
            'role' => 'viewer',
        ])['status']);

        // Nor may he nest anything inside it. A notebook he owned in there
        // would be invisible to Alice, who owns the notebook containing it.
        $nested = $this->bob->post('/notebooks', ['name' => 'Bob sub', 'parent_id' => $notebook['id']]);
        $this->assertSame(403, $nested['status']);

        $mine = $this->notebook($this->bob, 'Bob root');
        $this->assertSame(403, $this->bob->post('/notebooks/' . $mine['id'] . '/move', [
            'parent_id' => $notebook['id'],
        ])['status']);

        // Filing a *note* into the shared notebook still works: notes cascade.
        $this->assertSame(201, $this->bob->post('/notes', [
            'notebook_id' => $notebook['id'],
            'document' => Support::doc('a contribution'),
        ])['status']);
    }

    public function testAViewerCannotRename(): void
    {
        $notebook = $this->notebook($this->alice, 'Read only');
        $this->shareNotebook($notebook['id'], 'user-b', 'viewer');

        $result = $this->bob->patch('/notebooks/' . $notebook['id'], ['name' => 'nope']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('NOTEBOOK_ACCESS_DENIED', $result['body']['error']['code']);
    }

    public function testOneTenantCannotSeeAnothersNotebooks(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $globex = new ApiClient(Support::user('b', 'tenant-globex'));

        $notebook = $acme->post('/notebooks', ['name' => 'Acme accounts'])['body']['data'];

        // Even an explicit membership row cannot cross the boundary: the tenant
        // gate is an AND on top of every grant.
        $this->shareNotebook($notebook['id'], 'user-b', 'editor');

        $this->assertSame(404, $globex->get('/notebooks/' . $notebook['id'])['status']);
        $this->assertCount(0, $globex->get('/notebooks')['body']['data']);

        $insideAcme = new ApiClient(Support::user('b', 'tenant-acme'));
        $this->assertSame(200, $insideAcme->get('/notebooks/' . $notebook['id'])['status']);
    }

    public function testAMalformedNotebookIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/notebooks/not-a-uuid')['status']);
        $this->assertSame(404, $this->alice->get('/notebooks/' . Uuid::v4())['status']);
        $this->assertSame(404, $this->alice->delete('/notebooks/' . Uuid::v4())['status']);
    }

    public function testAReplayedCreateDoesNotProduceTwoNotebooks(): void
    {
        $id = Uuid::v4();
        $first = $this->alice->post('/notebooks', ['id' => $id, 'name' => 'Offline']);
        $second = $this->alice->post('/notebooks', ['id' => $id, 'name' => 'Offline']);

        $this->assertSame($id, $first['body']['data']['id']);
        $this->assertSame($id, $second['body']['data']['id']);

        $count = Connection::selectOne('SELECT count(*) AS c FROM notebooks');
        $this->assertSame(1, (int) $count['c']);
    }
}
