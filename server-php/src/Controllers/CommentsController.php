<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Collaboration\CommentService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for comments.
 *
 * Thin on purpose. Threading, anchoring, orphan detection and who may edit or
 * delete what all live in {@see CommentService}; the only decision here is
 * which of those the request is asking for.
 *
 * The comment routes are addressed by comment id alone — `/comments/{id}` —
 * rather than nested under their note, because a client acting on a comment
 * has its id and nothing else. The note is resolved from the comment, and the
 * permission check happens against that note, so the shorter path is not a
 * weaker one.
 */
final class CommentsController
{
    public function __construct(private readonly CommentService $comments = new CommentService())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        $page = $this->comments->listForNote($identity, $request->uuidParam('id'), [
            // `?resolved=open` is the panel's default view; `all` is what the
            // "show resolved" toggle asks for.
            'resolved' => $request->queryString('resolved', 'all'),
            'block_id' => $request->queryString('block_id'),
        ]);

        return Response::ok($page['threads'], [
            'total' => $page['total'],
            'unresolved' => $page['unresolved'],
            'has_more' => $page['has_more'],
        ]);
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->comments->create($identity, $request->uuidParam('id'), $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->comments->update($identity, $request->uuidParam('commentId'), $request->body()));
    }

    public function resolve(Request $request, Identity $identity): Response
    {
        return Response::ok($this->comments->resolve($identity, $request->uuidParam('commentId')));
    }

    public function reopen(Request $request, Identity $identity): Response
    {
        return Response::ok($this->comments->reopen($identity, $request->uuidParam('commentId')));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->comments->delete($identity, $request->uuidParam('commentId'));

        return Response::noContent();
    }
}
