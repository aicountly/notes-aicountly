<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Notebooks\NotebookService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for notebooks.
 *
 * Thin on purpose: parse, delegate, present. Every rule about the shape of the
 * tree — cycles, depth, what happens to the notes in a deleted notebook — lives
 * in {@see NotebookService}.
 */
final class NotebooksController
{
    public function __construct(
        private readonly NotebookService $notebooks = new NotebookService(),
    ) {
    }

    /** The whole visible tree. Archived branches are hidden unless asked for. */
    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->tree(
            $identity,
            includeArchived: $request->queryBool('include_archived') === true,
        ));
    }

    public function show(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->get($identity, $request->uuidParam('id')));
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->notebooks->create($identity, $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->update($identity, $request->uuidParam('id'), $request->body()));
    }

    /**
     * Soft delete.
     *
     * Answers 200 with a summary rather than 204, because the notes and
     * sub-notebooks that were inside have gone somewhere and the user is owed
     * a sentence saying where.
     */
    public function destroy(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->delete($identity, $request->uuidParam('id')));
    }

    public function move(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->move($identity, $request->uuidParam('id'), $request->body()));
    }

    // -----------------------------------------------------------------------
    // Sharing
    // -----------------------------------------------------------------------

    public function members(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notebooks->members($identity, $request->uuidParam('id')));
    }

    /** POST doubles as "change this person's role", so the status says which happened. */
    public function addMember(Request $request, Identity $identity): Response
    {
        $result = $this->notebooks->addMember($identity, $request->uuidParam('id'), $request->body());

        return $result['created']
            ? Response::created($result['member'])
            : Response::ok($result['member']);
    }

    /**
     * `{userId}` is a portal id, not a UUID, so it is read as a plain route
     * parameter and validated by the service.
     */
    public function removeMember(Request $request, Identity $identity): Response
    {
        $this->notebooks->removeMember($identity, $request->uuidParam('id'), $request->param('userId'));

        return Response::noContent();
    }
}
