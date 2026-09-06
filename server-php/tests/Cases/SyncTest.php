<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * The offline queue, over the real router.
 *
 * Every case here is about one of the three things a sync endpoint gets wrong
 * in production: it applies a replayed operation twice, it lets one bad entry
 * poison the batch, or it resolves a conflict by overwriting. The tenancy cases
 * are last and are the ones that must never regress — a push is a batch of
 * writes with ids the caller chose.
 */
final class SyncTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Sync';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    /** @return array<string, mixed> One queued operation. */
    private static function op(string $operation, string $entityId, array $payload = [], ?string $id = null): array
    {
        return [
            'operation_id' => $id ?? Uuid::v4(),
            'entity_type' => 'note',
            'entity_id' => $entityId,
            'operation' => $operation,
            'payload' => $payload,
            'client_stamp' => '2026-01-01T09:00:00Z',
        ];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function push(ApiClient $api, array $operations): array
    {
        return $api->post('/sync/push', ['operations' => $operations]);
    }

    // -- Applying a queue ---------------------------------------------------

    public function testAppliesAQueueOfOperationsInOrder(): void
    {
        $noteId = Uuid::v4();

        $result = $this->push($this->alice, [
            self::op('note.create', $noteId, ['title' => 'From the train', 'document' => Support::doc('offline draft')]),
            self::op('note.update', $noteId, ['document' => Support::doc('offline draft, revised'), 'version' => 1]),
            self::op('note.pin', $noteId),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame(3, $result['body']['meta']['applied']);
        $this->assertSame(0, $result['body']['meta']['rejected']);

        $note = $this->alice->get('/notes/' . $noteId)['body']['data'];
        $this->assertSame('From the train', $note['title']);
        $this->assertTrue($note['is_pinned']);
        $this->assertSame(2, $note['version'], 'one content edit advanced the version exactly once');
    }

    public function testTheIdTheDeviceUsedOfflineIsTheIdTheNoteKeeps(): void
    {
        $noteId = Uuid::v4();

        $this->push($this->alice, [self::op('note.create', $noteId, ['document' => Support::doc('local first')])]);

        $this->assertSame(200, $this->alice->get('/notes/' . $noteId)['status']);
    }

    // -- Replay -------------------------------------------------------------

    public function testReplayingABatchAppliesNothingTwice(): void
    {
        $noteId = Uuid::v4();
        $operations = [
            self::op('note.create', $noteId, ['title' => 'Once', 'document' => Support::doc('once')]),
            self::op('note.update', $noteId, ['title' => 'Once, edited', 'version' => 1]),
        ];

        $first = $this->push($this->alice, $operations);
        $second = $this->push($this->alice, $operations);

        $this->assertSame(2, $first['body']['meta']['applied']);
        $this->assertFalse($first['body']['data'][0]['replayed']);
        $this->assertTrue($second['body']['data'][0]['replayed'], 'the second send replays rather than applies');
        $this->assertTrue($second['body']['data'][1]['replayed']);

        // The version is the proof: applying the update twice would make it 3.
        $note = $this->alice->get('/notes/' . $noteId)['body']['data'];
        $this->assertSame(2, $note['version']);
        $this->assertSame('Once, edited', $note['title']);

        $this->assertCount(1, $this->alice->get('/notes')['body']['data']);
    }

    public function testAReplayedCreateReturnsTheOriginalAnswer(): void
    {
        $noteId = Uuid::v4();
        $operation = self::op('note.create', $noteId, ['title' => 'Draft', 'document' => Support::doc('body')]);

        $first = $this->push($this->alice, [$operation])['body']['data'][0];
        $second = $this->push($this->alice, [$operation])['body']['data'][0];

        $this->assertSame('applied', $second['status']);
        $this->assertSame($first['note']['id'], $second['note']['id']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
    }

    public function testAnOperationWithoutAUuidIsRejectedRatherThanApplied(): void
    {
        $noteId = Uuid::v4();
        $operation = self::op('note.create', $noteId, ['document' => Support::doc('no id')]);
        $operation['operation_id'] = 'not-a-uuid';

        $result = $this->push($this->alice, [$operation]);

        $this->assertSame(1, $result['body']['meta']['rejected']);
        $this->assertSame('OPERATION_ID_INVALID', $result['body']['data'][0]['error']['code']);
        $this->assertSame(404, $this->alice->get('/notes/' . $noteId)['status'], 'nothing was created');
    }

    // -- Conflicts ----------------------------------------------------------

    public function testAStaleUpdateConflictsAndDoesNotOverwrite(): void
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Shared numbers',
            'document' => Support::doc('server truth'),
        ])['body']['data'];

        // Someone else's device saved first, so the server has moved on.
        $this->alice->patch('/notes/' . $note['id'], [
            'document' => Support::doc('saved from the desktop'),
            'version' => $note['version'],
        ]);

        $result = $this->push($this->alice, [
            self::op('note.update', $note['id'], [
                'document' => Support::doc('written on the train'),
                'version' => $note['version'],
            ]),
        ]);

        $entry = $result['body']['data'][0];
        $this->assertSame(200, $result['status'], 'a conflict is reported, not thrown');
        $this->assertSame('conflict', $entry['status']);
        $this->assertSame(1, $result['body']['meta']['conflicts']);
        $this->assertSame(2, $entry['server_version']);
        $this->assertSame(1, $entry['client_version']);
        $this->assertNotNull($entry['note'], 'the server note comes back so the user can choose');

        $current = $this->alice->get('/notes/' . $note['id'])['body']['data'];
        $this->assertContainsString('saved from the desktop', $current['excerpt']);
    }

    public function testOneConflictDoesNotStopTheRestOfTheBatch(): void
    {
        $stale = $this->alice->post('/notes', ['document' => Support::doc('first')])['body']['data'];
        $this->alice->patch('/notes/' . $stale['id'], ['document' => Support::doc('moved on'), 'version' => 1]);

        $fresh = Uuid::v4();

        $result = $this->push($this->alice, [
            self::op('note.update', $stale['id'], ['document' => Support::doc('stale edit'), 'version' => 1]),
            self::op('note.create', $fresh, ['title' => 'Still saved', 'document' => Support::doc('kept')]),
            self::op('note.favourite', $fresh),
        ]);

        $this->assertSame('conflict', $result['body']['data'][0]['status']);
        $this->assertSame(2, $result['body']['meta']['applied']);
        $this->assertSame(1, $result['body']['meta']['conflicts']);
        $this->assertTrue($this->alice->get('/notes/' . $fresh)['body']['data']['is_favourite']);
    }

    public function testAnUnknownOperationIsRejectedWithoutFailingTheBatch(): void
    {
        $noteId = Uuid::v4();

        $result = $this->push($this->alice, [
            ['operation_id' => Uuid::v4(), 'entity_id' => $noteId, 'operation' => 'note.purge', 'payload' => []],
            self::op('note.create', $noteId, ['document' => Support::doc('survives')]),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('rejected', $result['body']['data'][0]['status']);
        $this->assertSame(1, $result['body']['meta']['applied']);
        $this->assertSame(200, $this->alice->get('/notes/' . $noteId)['status']);
    }

    public function testAnOperationOnAMissingNoteIsRejectedNotFatal(): void
    {
        $result = $this->push($this->alice, [
            self::op('note.update', Uuid::v4(), ['title' => 'ghost', 'version' => 1]),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('rejected', $result['body']['data'][0]['status']);
        $this->assertSame('NOT_FOUND', $result['body']['data'][0]['error']['code']);
    }

    public function testABatchLargerThanTheCapIsRefusedOutright(): void
    {
        $operations = [];
        for ($i = 0; $i <= 100; $i++) {
            $operations[] = self::op('note.create', Uuid::v4(), ['document' => Support::doc('n' . $i)]);
        }

        $result = $this->push($this->alice, $operations);

        $this->assertSame(400, $result['status']);
        $this->assertCount(0, $this->alice->get('/notes')['body']['data'], 'nothing in an over-long batch is applied');
    }

    public function testTurningAFlagOffTravelsThroughTheQueueToo(): void
    {
        $note = $this->alice->post('/notes', ['document' => Support::doc('filed away')])['body']['data'];
        $this->alice->post('/notes/' . $note['id'] . '/archive');

        $result = $this->push($this->alice, [self::op('note.unarchive', $note['id'])]);

        $this->assertSame('applied', $result['body']['data'][0]['status']);
        $this->assertFalse($this->alice->get('/notes/' . $note['id'])['body']['data']['is_archived']);
    }

    // -- Checklist items ----------------------------------------------------

    /** @return array<string, mixed> The first action mirrored from a note's checklist. */
    private function firstAction(ApiClient $api, string $noteId): array
    {
        return $api->get('/notes/' . $noteId . '/actions')['body']['data'][0];
    }

    public function testAnItemTickedOfflineIsCompletedOnTheServer(): void
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Monday',
            'document' => Support::checklist([['text' => 'Send the reminder', 'checked' => false]]),
        ])['body']['data'];
        $action = $this->firstAction($this->alice, $note['id']);

        $result = $this->push($this->alice, [[
            'operation_id' => Uuid::v4(),
            'entity_type' => 'action',
            'entity_id' => $action['id'],
            'operation' => 'action.complete',
            'payload' => ['status' => 'done'],
            'client_stamp' => '2026-01-01T09:00:00Z',
        ]]);

        $entry = $result['body']['data'][0];
        $this->assertSame('applied', $entry['status']);
        $this->assertSame('action', $entry['entity_type']);
        $this->assertSame('done', $entry['action']['status']);
        $this->assertSame('done', $this->firstAction($this->alice, $note['id'])['status']);
    }

    public function testAnotherUserCannotTickAnItemOnANoteTheyCannotOpen(): void
    {
        $note = $this->alice->post('/notes', [
            'document' => Support::checklist([['text' => 'Alice only', 'checked' => false]]),
        ])['body']['data'];
        $action = $this->firstAction($this->alice, $note['id']);

        // The action id carries no note in it, so without resolving the note
        // first the id would itself be the authorisation.
        $result = $this->push($this->bob, [[
            'operation_id' => Uuid::v4(),
            'entity_type' => 'action',
            'entity_id' => $action['id'],
            'operation' => 'action.complete',
            'payload' => ['status' => 'done'],
        ]]);

        $this->assertSame('rejected', $result['body']['data'][0]['status']);
        $this->assertSame('NOT_FOUND', $result['body']['data'][0]['error']['code']);
        $this->assertSame('open', $this->firstAction($this->alice, $note['id'])['status']);
    }

    // -- Trash and restore --------------------------------------------------

    public function testTrashAndRestoreTravelThroughTheQueue(): void
    {
        $note = $this->alice->post('/notes', ['document' => Support::doc('temporary')])['body']['data'];

        $trashed = $this->push($this->alice, [self::op('note.trash', $note['id'])]);
        $this->assertSame('applied', $trashed['body']['data'][0]['status']);
        $this->assertSame(404, $this->alice->get('/notes/' . $note['id'])['status']);

        $restored = $this->push($this->alice, [self::op('note.restore', $note['id'])]);
        $this->assertSame('applied', $restored['body']['data'][0]['status']);
        $this->assertSame(200, $this->alice->get('/notes/' . $note['id'])['status']);
    }

    // -- Pull ---------------------------------------------------------------

    public function testAFullPullReturnsEveryNoteAndACursor(): void
    {
        $this->alice->post('/notes', ['title' => 'One', 'document' => Support::doc('one')]);
        $this->alice->post('/notes', ['title' => 'Two', 'document' => Support::doc('two')]);

        $pull = $this->alice->get('/sync/pull');

        $this->assertSame(200, $pull['status']);
        $this->assertCount(2, $pull['body']['data']['notes']);
        $this->assertCount(0, $pull['body']['data']['deleted_note_ids']);
        $this->assertFalse($pull['body']['meta']['has_more']);
        $this->assertNotSame('', $pull['body']['meta']['cursor']);
    }

    public function testAPullListDoesNotShipEveryDocument(): void
    {
        $this->alice->post('/notes', ['title' => 'One', 'document' => Support::doc('the whole body')]);

        $note = $this->alice->get('/sync/pull')['body']['data']['notes'][0];

        // The card fields are there; the ProseMirror tree is not.
        $this->assertFalse(array_key_exists('document', $note));
        $this->assertSame(1, $note['version'], 'the version is what tells a client its cache is stale');
    }

    public function testTheCursorReturnsOnlyWhatChangedAfterIt(): void
    {
        $this->alice->post('/notes', ['title' => 'Before', 'document' => Support::doc('before')]);
        $cursor = $this->alice->get('/sync/pull')['body']['meta']['cursor'];

        $this->assertCount(0, $this->alice->get('/sync/pull', ['since' => $cursor])['body']['data']['notes']);

        $this->alice->post('/notes', ['title' => 'After', 'document' => Support::doc('after')]);

        $delta = $this->alice->get('/sync/pull', ['since' => $cursor]);
        $this->assertCount(1, $delta['body']['data']['notes']);
        $this->assertSame('After', $delta['body']['data']['notes'][0]['title']);
    }

    public function testDeletedNotesComeBackAsIdsSoAClientCanDropThem(): void
    {
        $kept = $this->alice->post('/notes', ['title' => 'Kept', 'document' => Support::doc('kept')])['body']['data'];
        $binned = $this->alice->post('/notes', ['title' => 'Binned', 'document' => Support::doc('binned')])['body']['data'];

        $cursor = $this->alice->get('/sync/pull')['body']['meta']['cursor'];
        $this->alice->delete('/notes/' . $binned['id']);

        $delta = $this->alice->get('/sync/pull', ['since' => $cursor]);

        $this->assertCount(1, $delta['body']['data']['deleted_note_ids']);
        $this->assertSame($binned['id'], $delta['body']['data']['deleted_note_ids'][0]);
        $this->assertCount(0, $delta['body']['data']['notes'], 'a trashed note is not also listed as changed');
        unset($kept);
    }

    public function testADeletionIsNotReportedTwiceOnceTheCursorHasPassedIt(): void
    {
        $note = $this->alice->post('/notes', ['document' => Support::doc('temporary')])['body']['data'];
        $cursor = $this->alice->get('/sync/pull')['body']['meta']['cursor'];

        $this->alice->delete('/notes/' . $note['id']);

        $first = $this->alice->get('/sync/pull', ['since' => $cursor]);
        $this->assertCount(1, $first['body']['data']['deleted_note_ids']);

        $second = $this->alice->get('/sync/pull', ['since' => $first['body']['meta']['cursor']]);
        $this->assertCount(0, $second['body']['data']['deleted_note_ids']);
    }

    public function testAPageIsCappedAndSaysThereIsMore(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->alice->post('/notes', ['title' => 'Note ' . $i, 'document' => Support::doc('body ' . $i)]);
        }

        $page = $this->alice->get('/sync/pull', ['limit' => '2']);
        $this->assertCount(2, $page['body']['data']['notes']);
        $this->assertTrue($page['body']['meta']['has_more']);

        $next = $this->alice->get('/sync/pull', ['since' => $page['body']['meta']['cursor'], 'limit' => '2']);
        $this->assertCount(2, $next['body']['data']['notes']);
        $this->assertFalse($next['body']['meta']['has_more']);

        $seen = array_merge(
            array_map(static fn (array $n): string => $n['id'], $page['body']['data']['notes']),
            array_map(static fn (array $n): string => $n['id'], $next['body']['data']['notes']),
        );
        $this->assertCount(4, array_unique($seen), 'paging neither repeats nor skips a note');
    }

    public function testAnUnreadableCursorFallsBackToAFullSync(): void
    {
        $this->alice->post('/notes', ['document' => Support::doc('one')]);

        $pull = $this->alice->get('/sync/pull', ['since' => 'not-a-cursor-or-a-date']);

        $this->assertSame(200, $pull['status']);
        $this->assertCount(1, $pull['body']['data']['notes']);
    }

    public function testAPlainIsoTimestampWorksAsACursor(): void
    {
        $this->alice->post('/notes', ['title' => 'Old', 'document' => Support::doc('old')]);

        $this->assertCount(1, $this->alice->get('/sync/pull', [
            'since' => '2000-01-01T00:00:00Z',
        ])['body']['data']['notes']);
        $this->assertCount(0, $this->alice->get('/sync/pull', [
            'since' => '2999-01-01T00:00:00Z',
        ])['body']['data']['notes']);
    }

    // -- Other people -------------------------------------------------------

    public function testAnotherUsersNotesAreNeverInAPull(): void
    {
        $this->alice->post('/notes', ['title' => 'Alice only', 'document' => Support::doc('private working papers')]);

        $pull = $this->bob->get('/sync/pull');

        $this->assertSame(200, $pull['status']);
        $this->assertCount(0, $pull['body']['data']['notes'], "bob's pull cannot reach alice's notes");
        $this->assertCount(0, $pull['body']['data']['deleted_note_ids']);
    }

    public function testAnotherUserCannotPushAnEditOntoANoteTheyCannotSee(): void
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Alice only',
            'document' => Support::doc('untouched'),
        ])['body']['data'];

        $result = $this->push($this->bob, [
            self::op('note.update', $note['id'], ['document' => Support::doc('bob was here'), 'version' => 1]),
            self::op('note.trash', $note['id']),
        ]);

        $this->assertSame('rejected', $result['body']['data'][0]['status']);
        $this->assertSame('NOT_FOUND', $result['body']['data'][0]['error']['code']);
        $this->assertSame('rejected', $result['body']['data'][1]['status']);

        $current = $this->alice->get('/notes/' . $note['id']);
        $this->assertSame(200, $current['status'], 'the note is still there');
        $this->assertContainsString('untouched', $current['body']['data']['excerpt']);
    }

    public function testAViewerCannotPushAnEditThroughTheQueue(): void
    {
        $note = $this->alice->post('/notes', ['document' => Support::doc('read only')])['body']['data'];
        $this->alice->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'viewer']);

        $result = $this->push($this->bob, [
            self::op('note.update', $note['id'], ['document' => Support::doc('edited anyway'), 'version' => 1]),
        ]);

        $this->assertSame('rejected', $result['body']['data'][0]['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $result['body']['data'][0]['error']['code']);
        $this->assertContainsString('read only', $this->alice->get('/notes/' . $note['id'])['body']['data']['excerpt']);
    }

    public function testAnOperationIdBelongingToSomeoneElseIsRefusedNotReplayed(): void
    {
        $noteId = Uuid::v4();
        $operationId = Uuid::v4();

        $this->push($this->alice, [
            self::op('note.create', $noteId, ['title' => 'Alice only', 'document' => Support::doc('secret')], $operationId),
        ]);

        // Bob's device happens to mint the same id. Replaying alice's stored
        // answer would hand him her note.
        $result = $this->push($this->bob, [
            self::op('note.create', Uuid::v4(), ['document' => Support::doc('bob note')], $operationId),
        ]);

        $entry = $result['body']['data'][0];
        $this->assertSame('rejected', $entry['status']);
        $this->assertSame('OPERATION_ID_TAKEN', $entry['error']['code']);
        $this->assertFalse(array_key_exists('note', $entry), 'no part of the other answer comes back');
    }

    public function testAReplayStopsCarryingTheNoteOnceAccessIsWithdrawn(): void
    {
        $note = $this->alice->post('/notes', ['document' => Support::doc('shared for now')])['body']['data'];
        $this->alice->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'editor']);

        $operation = self::op('note.update', $note['id'], ['title' => 'Bob renamed it', 'version' => 1]);
        $this->assertSame('applied', $this->push($this->bob, [$operation])['body']['data'][0]['status']);

        $this->alice->delete('/notes/' . $note['id'] . '/members/user-b');

        $replay = $this->push($this->bob, [$operation])['body']['data'][0];
        $this->assertTrue($replay['replayed']);
        $this->assertSame('applied', $replay['status'], 'the answer is still what the operation did');
        $this->assertFalse(array_key_exists('note', $replay), 'but the note itself is no longer handed back');
        $this->assertTrue($replay['note_unavailable']);
    }

    // -- The ledger ---------------------------------------------------------

    public function testTheLedgerRecordsOneRowPerOperationId(): void
    {
        $noteId = Uuid::v4();
        $operations = [self::op('note.create', $noteId, ['document' => Support::doc('once')])];

        $this->push($this->alice, $operations);
        $this->push($this->alice, $operations);

        $row = Connection::selectOne('SELECT count(*) AS n FROM sync_operations');
        $this->assertSame(1, (int) $row['n']);
    }

    public function testExpiredLedgerRowsCanBeSweptAway(): void
    {
        $this->push($this->alice, [self::op('note.create', Uuid::v4(), ['document' => Support::doc('old')])]);
        Connection::execute("UPDATE sync_operations SET created_at = now() - interval '90 days'");

        $purged = (new \Aicountly\Api\Domain\Sync\SyncService())->purgeExpiredOperations(30);

        $this->assertSame(1, $purged);
        $this->assertSame(0, (int) Connection::selectOne('SELECT count(*) AS n FROM sync_operations')['n']);
    }
}
