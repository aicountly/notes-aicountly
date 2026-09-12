<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Collaboration\ShareService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for a note's members.
 *
 * Thin on purpose. Who may share, which roles may be granted and what happens
 * to the owner's own grant are all rules in {@see ShareService}; this class
 * only says what the request asked for.
 */
final class MembersController
{
    public function __construct(private readonly ShareService $sharing = new ShareService())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->sharing->members($identity, $request->uuidParam('id')));
    }

    /** POST doubles as "change this person's role", so the status says which happened. */
    public function store(Request $request, Identity $identity): Response
    {
        $result = $this->sharing->addMember($identity, $request->uuidParam('id'), $request->body());

        return $result['created']
            ? Response::created($result['member'])
            : Response::ok($result['member']);
    }

    /**
     * `{userId}` is a portal id, not a UUID, so it is read as a plain route
     * parameter and validated by the service.
     */
    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->sharing->updateMember(
            $identity,
            $request->uuidParam('id'),
            $request->param('userId'),
            $request->body(),
        ));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->sharing->removeMember($identity, $request->uuidParam('id'), $request->param('userId'));

        return Response::noContent();
    }
}
