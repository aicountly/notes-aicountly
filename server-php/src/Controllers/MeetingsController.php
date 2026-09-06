<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Meetings\MeetingService;
use Aicountly\Api\Domain\Meetings\TranscriptService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for the meeting half of a note, and its transcripts.
 *
 * Thin on purpose: parse, delegate, present. Who may read a meeting, what a
 * participant list may contain and what a transcript correction is allowed to
 * touch all live in {@see MeetingService} and {@see TranscriptService} — a
 * check written here would be a second, unreviewed copy of the rule that keeps
 * one person's minutes out of another's hands.
 *
 * Note the path shapes. A meeting is addressed through its note, because the
 * note is the thing permissions are decided on. A transcript is corrected by
 * its own id, and the id alone is never the authorisation: the service resolves
 * the row, then asks whether the caller may edit the note it belongs to.
 */
final class MeetingsController
{
    public function __construct(
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly TranscriptService $transcripts = new TranscriptService(),
    ) {
    }

    /**
     * `GET /notes/{id}/meeting`
     *
     * Answers 200 with an empty meeting for a note that has never had one,
     * rather than 404. A meeting panel that has not been filled in yet is not a
     * missing resource, and answering 404 would push every client into treating
     * an expected state as an error.
     */
    public function show(Request $request, Identity $identity): Response
    {
        return Response::ok($this->meetings->get($identity, $request->uuidParam('id')));
    }

    /** `PATCH /notes/{id}/meeting` — upserts; absent fields are left alone. */
    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok(
            $this->meetings->upsert($identity, $request->uuidParam('id'), $request->body()),
        );
    }

    /** `GET /notes/{id}/transcripts` */
    public function transcripts(Request $request, Identity $identity): Response
    {
        return Response::ok($this->transcripts->listForNote($identity, $request->uuidParam('id')));
    }

    /**
     * `PATCH /transcripts/{transcriptId}` — correct the words, keep the audio.
     *
     * The body carries `edited_text` and nothing else that matters: `text` is
     * the provider's own and is not writable through this endpoint.
     */
    public function updateTranscript(Request $request, Identity $identity): Response
    {
        return Response::ok(
            $this->transcripts->correct($identity, $request->uuidParam('transcriptId'), $request->body()),
        );
    }
}
