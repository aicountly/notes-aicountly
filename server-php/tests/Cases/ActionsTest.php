<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Checklist actions over the real router.
 *
 * The case that matters here is the last group: `/actions/{id}` names no note,
 * so if the id were enough to act on one, an action id would be a way around
 * every note permission the rest of the API enforces.
 */
final class ActionsTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Actions';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    private function note(string $text = 'planning'): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Alice note',
            'document' => Support::doc($text),
        ])['body']['data'];
    }

    private function action(string $noteId, string $text = 'File the GST return'): array
    {
        return $this->alice->post('/notes/' . $noteId . '/actions', ['text' => $text])['body']['data'];
    }

    private function share(string $noteId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, \'user-b\', :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'role' => $role],
        );
    }

    // -- The checklist in the document --------------------------------------

    public function testChecklistItemsInTheDocumentBecomeActions(): void
    {
        $note = $this->alice->post('/notes', [
            'note_type' => 'checklist',
            'document' => Support::checklist([
                ['text' => 'Collect invoices', 'checked' => true],
                ['text' => 'Reconcile 2B'],
            ]),
        ])['body']['data'];

        $actions = $this->alice->get('/notes/' . $note['id'] . '/actions');
        $this->assertSame(200, $actions['status']);
        $this->assertCount(2, $actions['body']['data']);
        $this->assertSame('Collect invoices', $actions['body']['data'][0]['text']);
        $this->assertSame('done', $actions['body']['data'][0]['status'], 'a ticked box arrives as done');
        $this->assertSame('open', $actions['body']['data'][1]['status']);
        $this->assertSame('checklist', $actions['body']['data'][0]['origin']);
    }

    // -- The panel ----------------------------------------------------------

    public function testCreatesUpdatesAndDeletesAnAction(): void
    {
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/actions', [
            'text' => 'File the GST return',
            'priority' => 'high',
            'due_at' => '2026-10-20T09:00:00Z',
        ]);
        $this->assertSame(201, $created['status']);
        $action = $created['body']['data'];
        $this->assertSame('high', $action['priority']);
        $this->assertSame('manual', $action['origin']);
        $this->assertNull($action['completed_at']);

        $done = $this->alice->patch('/actions/' . $action['id'], ['status' => 'done']);
        $this->assertSame(200, $done['status']);
        $this->assertSame('done', $done['body']['data']['status']);
        $this->assertNotNull($done['body']['data']['completed_at']);

        $this->assertSame(204, $this->alice->delete('/actions/' . $action['id'])['status']);
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/actions')['body']['data']);
    }

    public function testAnActionNeedsSomeText(): void
    {
        $note = $this->note();

        $result = $this->alice->post('/notes/' . $note['id'] . '/actions', ['text' => '   ']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $action = $this->action($this->note()['id']);

        $result = $this->alice->patch('/actions/' . $action['id'], ['status' => 'almost']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('open', $this->alice->get('/notes/' . $action['note_id'] . '/actions')['body']['data'][0]['status']);
    }

    // -- The caller's own open actions --------------------------------------

    public function testOpenActionsSpanNotesAndExcludeFinishedOnes(): void
    {
        $first = $this->note('one');
        $second = $this->note('two');
        $this->action($first['id'], 'Call the client');
        $finished = $this->action($second['id'], 'Send the draft');
        $this->alice->patch('/actions/' . $finished['id'], ['status' => 'done']);

        $open = $this->alice->get('/actions');
        $this->assertSame(200, $open['status']);
        $this->assertCount(1, $open['body']['data']);
        $this->assertSame('Call the client', $open['body']['data'][0]['text']);
        // The list is read across notes, so it says which note each came from.
        $this->assertSame($first['id'], $open['body']['data'][0]['note_id']);
        $this->assertSame('Alice note', $open['body']['data'][0]['note_title']);
    }

    public function testOpenActionsOnlyCoverNotesTheCallerCanSee(): void
    {
        $note = $this->note();
        $this->action($note['id'], 'Alice only');

        $this->assertCount(0, $this->bob->get('/actions')['body']['data']);

        $this->share($note['id'], 'editor');
        $this->assertCount(1, $this->bob->get('/actions')['body']['data'], 'a share brings the note\'s actions with it');
    }

    // -- An action id is not a way around a note permission -----------------

    public function testAStrangerCannotReachAnActionThroughItsId(): void
    {
        $note = $this->note();
        $action = $this->action($note['id']);

        // 404 everywhere: an action id must not confirm that the note exists,
        // let alone let someone act on it.
        $this->assertSame(404, $this->bob->patch('/actions/' . $action['id'], ['status' => 'done'])['status']);
        $this->assertSame(404, $this->bob->delete('/actions/' . $action['id'])['status']);
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/actions')['status']);
        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/actions', ['text' => 'sneaky'])['status']);

        $survivors = $this->alice->get('/notes/' . $note['id'] . '/actions')['body']['data'];
        $this->assertCount(1, $survivors);
        $this->assertSame('open', $survivors[0]['status'], 'nothing Bob sent was applied');
    }

    public function testAViewerCanReadActionsButNotChangeThem(): void
    {
        $note = $this->note();
        $action = $this->action($note['id']);
        $this->share($note['id'], 'viewer');

        $this->assertCount(1, $this->bob->get('/notes/' . $note['id'] . '/actions')['body']['data']);

        // Seen but not touched: this is a 403, because Bob can see the note.
        $denied = $this->bob->patch('/actions/' . $action['id'], ['status' => 'done']);
        $this->assertSame(403, $denied['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $denied['body']['error']['code']);
        $this->assertSame(403, $this->bob->delete('/actions/' . $action['id'])['status']);
        $this->assertSame(403, $this->bob->post('/notes/' . $note['id'] . '/actions', ['text' => 'nope'])['status']);
    }

    public function testAnEditorCanTickAnActionOff(): void
    {
        $note = $this->note();
        $action = $this->action($note['id']);
        $this->share($note['id'], 'editor');

        $done = $this->bob->patch('/actions/' . $action['id'], ['status' => 'done']);
        $this->assertSame(200, $done['status']);
        $this->assertSame('done', $done['body']['data']['status']);
    }

    public function testAnActionCannotBeReachedAcrossATenantBoundary(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $note = $acme->post('/notes', ['document' => Support::doc('acme numbers')])['body']['data'];
        $action = $acme->post('/notes/' . $note['id'] . '/actions', ['text' => 'Sign off'])['body']['data'];

        // Same user id, wrong company: the tenant gate is an AND on the grant.
        $globex = new ApiClient(Support::user('a', 'tenant-globex'));
        $this->assertSame(404, $globex->patch('/actions/' . $action['id'], ['status' => 'done'])['status']);
        $this->assertCount(0, $globex->get('/actions')['body']['data']);
    }

    public function testAMalformedOrUnknownActionIdIsNotFound(): void
    {
        $this->assertSame(404, $this->alice->patch('/actions/not-a-uuid', ['status' => 'done'])['status']);
        $this->assertSame(404, $this->alice->patch('/actions/' . Uuid::v4(), ['status' => 'done'])['status']);
        $this->assertSame(404, $this->alice->delete('/actions/' . Uuid::v4())['status']);
    }

    public function testADeletedActionStaysGone(): void
    {
        $action = $this->action($this->note()['id']);
        $this->alice->delete('/actions/' . $action['id']);

        // Soft-deleted rows are invisible to the id lookup, so the second
        // attempt is a 404 rather than a resurrection.
        $this->assertSame(404, $this->alice->patch('/actions/' . $action['id'], ['status' => 'open'])['status']);
    }
}
