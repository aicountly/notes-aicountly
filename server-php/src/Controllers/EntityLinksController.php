<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Links\EntityLinkService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for the links between a note and the rest of AICOUNTLY.
 *
 * Thin on purpose: parse, delegate, present. Which entity types exist, which
 * links may be removed and who may add one are all decided in
 * {@see EntityLinkService}.
 *
 * The one thing worth pointing out here is the ses_key. `store` hands the
 * caller's own bearer token to the service so a missing label can be looked up
 * in Contacts **as that user** — Contacts then applies its own sharing rules,
 * and this endpoint never becomes a way to read a name out of a directory the
 * caller has no access to.
 */
final class EntityLinksController
{
    public function __construct(
        private readonly EntityLinkService $links = new EntityLinkService(),
    ) {
    }

    /** `GET /notes/{id}/entities` */
    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->links->listForNote($identity, $request->uuidParam('id')));
    }

    /** `POST /notes/{id}/entities` */
    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->links->create(
            $identity,
            $request->uuidParam('id'),
            $request->body(),
            $request->bearerToken,
        ));
    }

    /** `DELETE /notes/{id}/entities/{linkId}` */
    public function destroy(Request $request, Identity $identity): Response
    {
        $this->links->delete($identity, $request->uuidParam('id'), $request->uuidParam('linkId'));

        return Response::noContent();
    }
}
