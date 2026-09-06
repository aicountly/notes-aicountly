<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

final class ZProbe2Test extends TestCase
{
    public function name(): string { return 'ZProbe2'; }

    /** Every package endpoint, for every role Bob can hold on Alice's note. */
    public function testAuthzMatrix(): void
    {
        foreach ([null, 'viewer', 'commenter', 'editor', 'notebook-viewer', 'notebook-editor', 'nested-notebook'] as $role) {
            Support::reset();
            $alice = new ApiClient(Support::user('a'));
            $bob = new ApiClient(Support::user('b'));

            $notebookId = null;
            if (str_contains((string) $role, 'notebook') || $role === 'nested-notebook') {
                $nb = $alice->post('/notebooks', ['name' => 'Parent'])['body']['data'];
                $notebookId = $nb['id'];
                if ($role === 'nested-notebook') {
                    $child = $alice->post('/notebooks', ['name' => 'Child', 'parent_id' => $nb['id']])['body']['data'];
                    $notebookId = $child['id'];
                }
            }

            $note = $alice->post('/notes', array_filter([
                'title' => 'N', 'document' => Support::doc('secret body'),
                'notebook_id' => str_contains((string) $role, 'notebook') || $role === 'nested-notebook' ? $notebookId : null,
            ]))['body']['data'];
            $action = $alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'do it'])['body']['data'];

            if (in_array($role, ['viewer', 'commenter', 'editor'], true)) {
                Connection::execute(
                    'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id,:n,\'user-b\',:r,\'user-a\')',
                    ['id' => Uuid::v4(), 'n' => $note['id'], 'r' => $role],
                );
            } elseif ($role === 'notebook-viewer' || $role === 'notebook-editor' || $role === 'nested-notebook') {
                $target = $role === 'nested-notebook'
                    ? Connection::selectOne('SELECT parent_id FROM notebooks WHERE id = :id', ['id' => $notebookId])['parent_id']
                    : $notebookId;
                Connection::execute(
                    'INSERT INTO notebook_members (id, notebook_id, user_id, role, invited_by) VALUES (:id,:nb,\'user-b\',:r,\'user-a\')',
                    ['id' => Uuid::v4(), 'nb' => $target, 'r' => $role === 'notebook-editor' ? 'editor' : 'viewer'],
                );
            }

            $out = [
                'GET /notes/x/actions' => $bob->get('/notes/' . $note['id'] . '/actions')['status'],
                'POST /notes/x/actions' => $bob->post('/notes/' . $note['id'] . '/actions', ['text' => 'x'])['status'],
                'PATCH /actions/x' => $bob->patch('/actions/' . $action['id'], ['status' => 'done'])['status'],
                'DELETE /actions/x' => $bob->delete('/actions/' . $action['id'])['status'],
                'GET /actions' => count($bob->get('/actions')['body']['data'] ?? []),
                'GET /notes/x/activity' => $bob->get('/notes/' . $note['id'] . '/activity')['status'],
            ];
            fwrite(STDERR, "\nROLE " . var_export($role, true) . ': ' . json_encode($out) . "\n");
        }
        $this->pass();
    }

    /** A note the caller cannot see, reached through an action id, across tenants. */
    public function testCrossTenantMatrix(): void
    {
        Support::reset();
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $note = $acme->post('/notes', ['document' => Support::doc('acme')])['body']['data'];
        $action = $acme->post('/notes/' . $note['id'] . '/actions', ['text' => 'x'])['body']['data'];
        // Bob is an editor member of the acme note but acts in globex.
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by) VALUES (:id,:n,\'user-b\',\'editor\',\'user-a\')',
            ['id' => Uuid::v4(), 'n' => $note['id']],
        );
        $bobGlobex = new ApiClient(Support::user('b', 'tenant-globex'));
        $bobAcme = new ApiClient(Support::user('b', 'tenant-acme'));
        fwrite(STDERR, "\nXTENANT globex actions idx=" . $bobGlobex->get('/notes/' . $note['id'] . '/actions')['status']
            . " patch=" . $bobGlobex->patch('/actions/' . $action['id'], ['status' => 'done'])['status']
            . " activity=" . $bobGlobex->get('/notes/' . $note['id'] . '/activity')['status']
            . " open=" . count($bobGlobex->get('/actions')['body']['data'] ?? []) . "\n");
        fwrite(STDERR, "XTENANT acme  actions idx=" . $bobAcme->get('/notes/' . $note['id'] . '/actions')['status']
            . " activity=" . $bobAcme->get('/notes/' . $note['id'] . '/activity')['status'] . "\n");
        // Tags across the same user's tenants.
        $acme->post('/tags', ['name' => 'acme-only-label']);
        $globex = new ApiClient(Support::user('a', 'tenant-globex'));
        fwrite(STDERR, "XTENANT tags in globex: " . json_encode(array_column($globex->get('/tags')['body']['data'], 'slug')) . "\n");
        $this->pass();
    }
}
