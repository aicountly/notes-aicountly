<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Ai\NotesAIService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for Pulse.
 *
 * Thin, and thinner here than anywhere else in this API on purpose: a
 * controller that decided anything about Pulse would be a second place where
 * "may this text be sent to a model?" is answered. The feature gate, the
 * permission checks, the rate-limit bucket, the prompt separation and the
 * citation rule all live in {@see NotesAIService}. This file parses a body and
 * names an action.
 *
 * The named routes — `summarize`, `extract-actions`, `meeting-summary` — are
 * shortcuts into the same structured call, the way the note flag endpoints
 * funnel into one update. There is one code path to a provider, so there is
 * one place where the rules can be checked.
 */
final class PulseController
{
    public function __construct(private readonly NotesAIService $pulse = new NotesAIService())
    {
    }

    /**
     * `GET /pulse/actions` — the catalogue, feature gate deliberately absent.
     *
     * Every other endpoint here answers 503 when Pulse is off. This one
     * answers, with `enabled: false` on each action, so the UI can render the
     * menu honestly — greyed out, with a reason — instead of showing a menu
     * that fails on click or hiding a capability the product has.
     */
    public function catalogue(Request $request, Identity $identity): Response
    {
        unset($request, $identity);

        return Response::ok($this->pulse->catalogue(), [
            'feature' => Features::AI,
            'enabled' => Features::enabled(Features::AI),
        ]);
    }

    /** `POST /pulse/selection` — one endpoint for all sixteen selection actions. */
    public function selection(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->selection($identity, $request->body()));
    }

    /** `POST /pulse/note/{id}/ask` — a question about one note, or any note-scoped action. */
    public function askNote(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->onNote(
            $identity,
            $request->uuidParam('id'),
            $request->string('action', 'ask_note'),
            $request->body(),
        ));
    }

    /** `POST /pulse/note/{id}/summarize` */
    public function summarizeNote(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->onNote($identity, $request->uuidParam('id'), 'summarise', $request->body()));
    }

    /**
     * `POST /pulse/note/{id}/extract-actions`
     *
     * Returns suggestions. `{"save": true}` also writes them to the note's
     * action list, which needs edit rights — see {@see NotesAIService}.
     */
    public function extractActions(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->onNote(
            $identity,
            $request->uuidParam('id'),
            'extract_actions',
            $request->body(),
        ));
    }

    /** `POST /pulse/note/{id}/meeting-summary` — minutes, decisions and actions from a meeting note. */
    public function meetingSummary(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->onNote(
            $identity,
            $request->uuidParam('id'),
            'meeting_minutes',
            $request->body(),
        ));
    }

    /** `POST /pulse/notebook/{id}/ask` — answered from that notebook and its descendants. */
    public function askNotebook(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->askNotebook($identity, $request->uuidParam('id'), $request->body()));
    }

    /** `POST /pulse/notes/ask` — answered from everything this caller may read. */
    public function askNotes(Request $request, Identity $identity): Response
    {
        return Response::ok($this->pulse->askNotes($identity, $request->body()));
    }
}
