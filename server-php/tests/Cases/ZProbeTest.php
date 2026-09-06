<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

final class ZProbeTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string { return 'ZProbe'; }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    private function note(string $text = 'planning'): array
    {
        return $this->alice->post('/notes', ['title' => 'N', 'document' => Support::doc($text)])['body']['data'];
    }

    public function testProbeActivityOffsetPastEnd(): void
    {
        $note = $this->note();
        $r = $this->alice->get('/notes/' . $note['id'] . '/activity', ['limit' => 5, 'offset' => 50]);
        fwrite(STDERR, "\nACTIVITY offset-past-end: " . json_encode($r) . "\n");
        $this->pass();
    }

    public function testProbeActivityWeirdQuery(): void
    {
        $note = $this->note();
        foreach ([['limit' => 'abc'], ['offset' => '-5'], ['limit' => '0'], ['offset' => '999999999']] as $q) {
            $r = $this->alice->get('/notes/' . $note['id'] . '/activity', $q);
            fwrite(STDERR, "\nACTIVITY q=" . json_encode($q) . " -> " . $r['status'] . " meta=" . json_encode($r['body']['meta'] ?? null) . "\n");
        }
        $this->pass();
    }

    public function testProbeActionWeirdBodies(): void
    {
        $note = $this->note();
        $bodies = [
            ['text' => 'ok', 'priority' => ['a']],
            ['text' => ['a'], 'priority' => 'high'],
            ['text' => 'ok', 'due_at' => ['a']],
            ['text' => 'ok', 'due_at' => '9999999-01-01T00:00:00Z'],
            ['text' => 'ok', 'due_at' => 'tomorrow'],
            ['text' => 'ok', 'assigned_user_id' => ['x']],
            ['text' => 'ok', 'assigned_user_id' => 12345],
            ['text' => str_repeat('x', 5000)],
            ['text' => "ok\x00bad"],
        ];
        foreach ($bodies as $i => $b) {
            try {
                $r = $this->alice->post('/notes/' . $note['id'] . '/actions', $b);
                fwrite(STDERR, "\nACTION create #$i -> " . $r['status'] . ' ' . json_encode($r['body']['error'] ?? array_intersect_key($r['body']['data'] ?? [], array_flip(['due_at','priority','assigned_user_id']))) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nACTION create #$i CRASH " . get_debug_type($e) . ': ' . $e->getMessage() . "\n");
            }
        }
        $this->pass();
    }

    public function testProbeActionUpdateWeird(): void
    {
        $note = $this->note();
        $a = $this->alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'x'])['body']['data'];
        $bodies = [
            ['status' => ['done']],
            ['status' => 1],
            ['due_at' => '9999999-01-01T00:00:00Z'],
            ['priority' => ['x']],
            ['assigned_user_id' => ['x']],
            ['text' => ['x']],
            ['note_id' => Uuid::v4()],
            [],
        ];
        foreach ($bodies as $i => $b) {
            try {
                $r = $this->alice->patch('/actions/' . $a['id'], $b);
                fwrite(STDERR, "\nACTION update #$i -> " . $r['status'] . ' ' . json_encode($r['body']['error'] ?? $r['body']['data']['note_id'] ?? null) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nACTION update #$i CRASH " . get_debug_type($e) . ': ' . $e->getMessage() . "\n");
            }
        }
        $this->pass();
    }

    public function testProbeTagsWeird(): void
    {
        $bodies = [
            ['name' => ['x']],
            ['name' => 12345],
            ['name' => str_repeat('é', 200)],
            ['name' => 'ok', 'color' => ['x']],
            ['name' => 'ok', 'color' => str_repeat('c', 200)],
            ['name' => '  #  '],
        ];
        foreach ($bodies as $i => $b) {
            try {
                $r = $this->alice->post('/tags', $b);
                fwrite(STDERR, "\nTAG create #$i -> " . $r['status'] . ' ' . json_encode($r['body']['error'] ?? $r['body']['data']) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nTAG create #$i CRASH " . get_debug_type($e) . ': ' . $e->getMessage() . "\n");
            }
        }
        $this->pass();
    }

    public function testProbeTagMergeWeird(): void
    {
        $t1 = $this->alice->post('/tags', ['name' => 'one'])['body']['data'];
        $t2 = $this->alice->post('/tags', ['name' => 'two'])['body']['data'];
        $cases = [
            ['source_ids' => 'nope', 'target_id' => $t1['id']],
            ['source_ids' => [$t1['id']], 'target_id' => $t1['id']],
            ['source_ids' => [['x']], 'target_id' => $t1['id']],
            ['source_ids' => [$t2['id'], $t2['id']], 'target_id' => $t1['id']],
            ['target_id' => $t1['id']],
            ['source_ids' => [$t2['id']]],
        ];
        foreach ($cases as $i => $b) {
            try {
                $r = $this->alice->post('/tags/merge', $b);
                fwrite(STDERR, "\nTAG merge #$i -> " . $r['status'] . ' ' . json_encode($r['body']['error'] ?? $r['body']['data']) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nTAG merge #$i CRASH " . get_debug_type($e) . ': ' . $e->getMessage() . "\n");
            }
        }
        $this->pass();
    }

    public function testProbeTagUpdateWeird(): void
    {
        $t = $this->alice->post('/tags', ['name' => 'one', 'color' => 'sage'])['body']['data'];
        $cases = [
            ['name' => null],
            ['color' => null],
            ['name' => ['x']],
            [],
            ['name' => '###'],
        ];
        foreach ($cases as $i => $b) {
            try {
                $r = $this->alice->patch('/tags/' . $t['id'], $b);
                fwrite(STDERR, "\nTAG update #$i -> " . $r['status'] . ' ' . json_encode($r['body']['error'] ?? $r['body']['data']) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nTAG update #$i CRASH " . get_debug_type($e) . ': ' . $e->getMessage() . "\n");
            }
        }
        $this->pass();
    }

    public function testProbeActionsOnTrashedNote(): void
    {
        $note = $this->note();
        $a = $this->alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'x'])['body']['data'];
        $this->alice->delete('/notes/' . $note['id']);
        fwrite(STDERR, "\nTRASHED index -> " . $this->alice->get('/notes/' . $note['id'] . '/actions')['status'] . "\n");
        fwrite(STDERR, "TRASHED patch -> " . $this->alice->patch('/actions/' . $a['id'], ['status' => 'done'])['status'] . "\n");
        fwrite(STDERR, "TRASHED open  -> " . json_encode($this->alice->get('/actions')['body']['data']) . "\n");
        $this->pass();
    }

    public function testProbeOpenActionsAssignedToOthers(): void
    {
        $note = $this->note();
        $this->alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'for bob', 'assigned_user_id' => 'user-b']);
        $this->alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'unassigned']);
        fwrite(STDERR, "\nOPEN alice: " . json_encode(array_column($this->alice->get('/actions')['body']['data'], 'text')) . "\n");
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id, :n, \'user-b\', \'viewer\', \'user-a\')',
            ['id' => Uuid::v4(), 'n' => $note['id']],
        );
        fwrite(STDERR, "OPEN bob: " . json_encode(array_column($this->bob->get('/actions')['body']['data'], 'text')) . "\n");
        $this->pass();
    }

    public function testProbeActivityPrivateNote(): void
    {
        $note = $this->note();
        Connection::execute('UPDATE notes SET privacy_mode = \'private\' WHERE id = :id', ['id' => $note['id']]);
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id, :n, \'user-b\', \'editor\', \'user-a\')',
            ['id' => Uuid::v4(), 'n' => $note['id']],
        );
        fwrite(STDERR, "\nPRIVATE activity bob -> " . $this->bob->get('/notes/' . $note['id'] . '/activity')['status'] . "\n");
        fwrite(STDERR, "PRIVATE actions bob -> " . $this->bob->get('/notes/' . $note['id'] . '/actions')['status'] . "\n");
        $this->pass();
    }
}
