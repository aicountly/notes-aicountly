<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Templates\NoteTemplateService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for note templates.
 *
 * Thin on purpose. Which templates a caller may see, who may edit one, and what
 * `{{date}}` means all live in {@see NoteTemplateService}; this class only
 * decides what the request asked for.
 */
final class TemplatesController
{
    public function __construct(private readonly NoteTemplateService $templates = new NoteTemplateService())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->templates->listForUser(
            $identity,
            includeArchived: $request->queryBool('include_archived') === true,
        ));
    }

    public function show(Request $request, Identity $identity): Response
    {
        return Response::ok($this->templates->get($identity, $request->uuidParam('id')));
    }

    /**
     * Create a template, or save an existing note as one.
     *
     * The two are the same endpoint because they produce the same thing:
     * `{"from_note_id": "…"}` simply says where the document comes from.
     */
    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->templates->create($identity, $request->body()));
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->templates->update($identity, $request->uuidParam('id'), $request->body()));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->templates->delete($identity, $request->uuidParam('id'));

        return Response::noContent();
    }

    /** Start a note from a template. Answers the note, exactly as POST /notes does. */
    public function createNote(Request $request, Identity $identity): Response
    {
        return Response::created($this->templates->createNote($identity, $request->uuidParam('id'), $request->body()));
    }
}
