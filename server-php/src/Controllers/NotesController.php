<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Links\NoteLinkService;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Domain\Notes\NoteQuery;
use Aicountly\Api\Domain\Notes\NoteRevisionService;
use Aicountly\Api\Domain\Notes\NotesService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Support\Str;

/**
 * HTTP for notes.
 *
 * Thin on purpose: parse, delegate, present. Any `if` in here that is not about
 * the shape of the request belongs in {@see NotesService}.
 */
final class NotesController
{
    public function __construct(
        private readonly NotesService $notes = new NotesService(),
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly NoteRevisionService $revisions = new NoteRevisionService(),
        private readonly NoteLinkService $links = new NoteLinkService(),
    ) {
    }

    public function index(Request $request, Identity $identity): Response
    {
        $scope = $request->queryString('scope', 'active');
        $filters = $this->filtersFromQuery($request);

        $result = $this->notes->list($identity, $filters, [
            'limit' => $request->queryInt('limit', 30, 1, 100),
            'cursor' => $request->queryString('cursor'),
            'sort' => $request->queryString('sort', 'updated_desc'),
            'scope' => $scope,
            'shared_with_me' => $request->queryBool('shared_with_me') === true,
        ]);

        return Response::ok($result['notes'], [
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    /** Sidebar badge counts — one request instead of six list calls. */
    public function counts(Request $request, Identity $identity): Response
    {
        unset($request);

        return Response::ok((new \Aicountly\Api\Domain\Notes\NoteRepository())->sidebarCounts($identity));
    }

    public function show(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notes->get(
            $identity,
            $request->uuidParam('id'),
            includeTrashed: $request->queryBool('include_trashed') === true,
        ));
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->notes->create($identity, $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notes->update($identity, $request->uuidParam('id'), $request->body()));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');

        if ($request->queryBool('permanent') === true) {
            $this->notes->purge($identity, $noteId);
        } else {
            $this->notes->trash($identity, $noteId);
        }

        return Response::noContent();
    }

    public function restore(Request $request, Identity $identity): Response
    {
        return Response::ok($this->notes->restore($identity, $request->uuidParam('id')));
    }

    public function duplicate(Request $request, Identity $identity): Response
    {
        return Response::created($this->notes->duplicate($identity, $request->uuidParam('id')));
    }

    /**
     * The small state toggles, as one handler.
     *
     * `POST /notes/{id}/pin` and friends exist because they are the actions a
     * UI actually performs; they all funnel into the same update so the rules
     * cannot diverge between the PATCH and the shortcut.
     */
    public function setFlag(Request $request, Identity $identity, string $field, bool $value): Response
    {
        return Response::ok($this->notes->update($identity, $request->uuidParam('id'), [$field => $value]));
    }

    // -----------------------------------------------------------------------
    // Version history
    // -----------------------------------------------------------------------

    public function versions(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');

        $limit = $request->queryInt('limit', 50, 1, 200);
        $offset = $request->queryInt('offset', 0, 0, 100000);

        return Response::ok($this->revisions->listForNote($noteId, $limit, $offset), [
            'total' => $this->revisions->countForNote($noteId),
        ]);
    }

    public function showVersion(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');

        return Response::ok($this->revisions->get($noteId, $request->uuidParam('versionId')));
    }

    /**
     * Roll a note back to an earlier version.
     *
     * The restore is itself an edit, so it goes through the normal update path:
     * the current document becomes a revision first, and the rollback can then
     * be rolled back. Nothing is lost by restoring the wrong version.
     */
    public function restoreVersion(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::EDIT, columns: 'n.id');

        $version = $this->revisions->get($noteId, $request->uuidParam('versionId'));

        $note = $this->notes->update($identity, $noteId, [
            'title' => $version['title'],
            'document' => $version['document'],
            'revision_reason' => 'restore',
        ]);

        (new \Aicountly\Api\Domain\Activity\ActivityRecorder())->record(
            $identity,
            \Aicountly\Api\Domain\Activity\ActivityRecorder::VERSION_RESTORED,
            $noteId,
            null,
            ['revision_number' => $version['revision_number']],
        );

        return Response::ok($note);
    }

    // -----------------------------------------------------------------------
    // Links
    // -----------------------------------------------------------------------

    public function backlinks(Request $request, Identity $identity): Response
    {
        $noteId = $request->uuidParam('id');
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');

        return Response::ok([
            'incoming' => $this->links->backlinks($identity, $noteId),
            'outgoing' => $this->links->outgoing($identity, $noteId),
        ]);
    }

    // -----------------------------------------------------------------------
    // Quick capture
    // -----------------------------------------------------------------------

    /**
     * The generic ingestion endpoint.
     *
     * Deliberately loose about where it is called from — web composer today, a
     * browser extension or share sheet later — and deliberately strict about
     * what it accepts. It takes plain text or a document, never HTML.
     */
    public function capture(Request $request, Identity $identity): Response
    {
        $type = $request->string('type', 'text');
        $content = $request->string('content');
        $title = $request->nullableString('title');

        if ($content === '' && !$request->has('document')) {
            throw ApiException::validation(['content' => 'There is nothing to capture.']);
        }

        $document = $request->has('document')
            ? NoteDocument::sanitize($request->input('document'))
            : NoteDocument::fromPlainText($content);

        $note = $this->notes->create($identity, [
            'id' => $request->input('id'),
            'title' => $title,
            'document' => $document,
            'note_type' => in_array($type, NotesService::NOTE_TYPES, true) ? $type : 'document',
            'notebook_id' => $request->input('notebook_id'),
            'tags' => $request->array('tags'),
            'source' => Str::limit($request->string('source', 'web'), 40),
        ]);

        return Response::created($note);
    }

    // -----------------------------------------------------------------------

    /** Translate `?tag=&notebook=&type=…` into the shared filter builder. */
    private function filtersFromQuery(Request $request): NoteQuery
    {
        $filters = NoteQuery::make();

        if (($notebook = $request->queryString('notebook_id')) !== '') {
            $filters->where('notebook', 'is', $notebook);
        }
        foreach ($request->queryList('tags') as $tag) {
            $filters->where('tag', 'is', $tag);
        }
        if (($type = $request->queryString('note_type')) !== '') {
            $filters->where('note_type', 'is', $type);
        }
        if (($color = $request->queryString('color')) !== '') {
            $filters->where('color', 'is', $color);
        }
        if ($request->queryBool('pinned') !== null) {
            $filters->where('is_pinned', 'is', $request->queryBool('pinned'));
        }
        if ($request->queryBool('favourite') !== null) {
            $filters->where('is_favourite', 'is', $request->queryBool('favourite'));
        }
        if ($request->queryBool('has_attachment') !== null) {
            $filters->where('has_attachment', 'is', $request->queryBool('has_attachment'));
        }
        if ($request->queryBool('has_reminder') !== null) {
            $filters->where('has_reminder', 'is', $request->queryBool('has_reminder'));
        }
        if (($q = $request->queryString('q')) !== '') {
            $filters->where('text', 'contains', $q);
        }

        return $filters;
    }
}
