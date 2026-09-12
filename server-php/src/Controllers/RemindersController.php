<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Reminders\ReminderService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for reminders.
 *
 * Thin on purpose: parse, delegate, present. Who owns a reminder, what a
 * recurrence rule may say, what completing a repeating one does and which notes
 * a reminder may hang off all live in {@see ReminderService} — a check written
 * here would be a second, unreviewed copy of the rule that decides whether one
 * person can read another's reminders.
 *
 * Note the path shapes: a reminder is created under its note
 * (`POST /notes/{id}/reminders`, so the note is named and can be authorised),
 * and changed by its own id afterwards. The id alone is never the
 * authorisation — the service resolves the row against the caller and then
 * re-checks the note.
 */
final class RemindersController
{
    public function __construct(
        private readonly ReminderService $reminders = new ReminderService(),
    ) {
    }

    /**
     * The caller's own reminders, across every note they can see.
     *
     * `?status=` picks a view — `open` (the default: overdue and upcoming
     * together, soonest first), `overdue`, `upcoming`, `completed`,
     * `cancelled` or `all`.
     */
    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->reminders->listForUser($identity, [
            'status' => $request->queryString('status', 'open'),
            'limit' => $request->queryInt('limit', 100, 1, 200),
        ]));
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created(
            $this->reminders->create($identity, $request->uuidParam('id'), $request->body()),
        );
    }

    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok(
            $this->reminders->update($identity, $request->uuidParam('reminderId'), $request->body()),
        );
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->reminders->delete($identity, $request->uuidParam('reminderId'));

        return Response::noContent();
    }

    public function snooze(Request $request, Identity $identity): Response
    {
        return Response::ok(
            $this->reminders->snooze($identity, $request->uuidParam('reminderId'), $request->body()),
        );
    }

    /**
     * Tick a reminder off.
     *
     * Answers 200 with the reminder either way, because a recurring one is
     * still there afterwards — moved on to its next occurrence — and the client
     * needs the new due date rather than a 204 that tells it nothing.
     */
    public function complete(Request $request, Identity $identity): Response
    {
        return Response::ok(
            $this->reminders->complete($identity, $request->uuidParam('reminderId')),
        );
    }
}
