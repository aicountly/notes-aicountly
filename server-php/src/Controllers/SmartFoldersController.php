<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\SmartFolders\SmartFolderService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for smart folders.
 *
 * Thin on purpose: parse, delegate, present. What a rule may say, what happens
 * to a folder whose rules no longer compile, and which notes a folder is
 * allowed to reach all live in {@see SmartFolderService} — a controller that
 * decided any of that would be a second, unreviewed copy of the rules.
 */
final class SmartFoldersController
{
    public function __construct(
        private readonly SmartFolderService $folders = new SmartFolderService(),
    ) {
    }

    public function index(Request $request, Identity $identity): Response
    {
        unset($request);

        return Response::ok($this->folders->listForUser($identity));
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->folders->create($identity, $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->folders->update($identity, $request->uuidParam('id'), $request->body()));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->folders->delete($identity, $request->uuidParam('id'));

        return Response::noContent();
    }

    /**
     * The notes a folder matches.
     *
     * Same pagination and sort vocabulary as `GET /notes`, because it is the
     * same list: a folder is a filter over it, not a different collection.
     */
    public function notes(Request $request, Identity $identity): Response
    {
        $result = $this->folders->notes($identity, $request->uuidParam('id'), [
            'limit' => $request->queryInt('limit', 30, 1, 100),
            'cursor' => $request->queryString('cursor'),
            'sort' => $request->queryString('sort', 'updated_desc'),
            // Empty rather than 'active': an absent scope lets the folder's own
            // rules choose, so a folder built around archived notes is not
            // permanently empty because the default view hides them.
            'scope' => $request->queryString('scope'),
        ]);

        return Response::ok($result['notes'], [
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }
}
