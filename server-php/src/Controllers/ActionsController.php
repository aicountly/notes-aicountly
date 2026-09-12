<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Actions\NoteActionService;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for the actions hanging off a note's checklist.
 *
 * Thin over {@see NoteActionService}: reconciliation with the document, the
 * status vocabulary and the due-date parsing are its business.
 *
 * The one thing this class is responsible for is where an action gets its
 * protection from — see {@see requireEditableNote()}.
 */
final class ActionsController
{
    /**
     * The note columns these checks need.
     *
     * `privacy_mode` travels with the id because requireNote refuses a private
     * note to anyone but its owner from that column — selecting the id alone
     * would quietly skip that guard.
     */
    private const COLUMNS = 'n.id, n.privacy_mode';

    public function __construct(
        private readonly NoteActionService $actions = new NoteActionService(),
        private readonly NotePermissionService $permissions = new NotePermissionService(),
    ) {
    }

    public function index(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: self::COLUMNS);

        return Response::ok($this->actions->listForNote($noteId));
    }

    public function store(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::EDIT, columns: self::COLUMNS);

        return Response::created($this->actions->create($identity, $noteId, $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        $actionId = $request->uuidParam('actionId');
        $this->requireEditableNote($identity, $actionId);

        return Response::ok($this->actions->update($identity, $actionId, $request->body()));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $actionId = $request->uuidParam('actionId');
        $this->requireEditableNote($identity, $actionId);
        $this->actions->delete($actionId);

        return Response::noContent();
    }

    /**
     * The caller's own open actions, across every note they can see.
     *
     * "Own" is assigned-to-them or unassigned, not everything on those notes —
     * see {@see NoteActionService::openForUser()}.
     */
    public function open(Request $request, Identity $identity): Response
    {
        return Response::ok($this->actions->openForUser($identity, $request->queryInt('limit', 50, 1, 200)));
    }

    /**
     * Authorise an action against the note it belongs to.
     *
     * `PATCH /actions/{id}` carries no note in its path, so without this the
     * action id *is* the authorisation: anyone who saw one in a shared export,
     * a log line or a stale client could tick off or delete an item on a note
     * they cannot open. The note is resolved first and the ordinary EDIT check
     * applied to it, which also means a viewer is refused here exactly as they
     * are on the note itself.
     *
     * An action that does not exist — or is already deleted — answers 404, the
     * same as one on a note the caller cannot see. Probing ids learns nothing.
     */
    private function requireEditableNote(Identity $identity, string $actionId): void
    {
        $noteId = $this->actions->noteIdFor($actionId);
        if ($noteId === null) {
            throw ApiException::notFound('That action');
        }

        $this->permissions->requireNote($identity, $noteId, NotePermissionService::EDIT, columns: self::COLUMNS);
    }
}
