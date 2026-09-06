# Pulse integration

**Status: adapter-ready, and the adapter needs correcting.** This document
records what was found by reading `aicountly/pulse-aicountly` (HEAD `a32f054`)
rather than what was assumed while building against it.

The headline: **Pulse does not expose a "complete this prompt" endpoint**, which
is the shape the Notes adapter was written against. What it exposes instead is a
session-and-message chat API, a set of product proxies, and one endpoint Notes
can use directly today.

## What Pulse actually is

A private AI assistant, not a model gateway. It classifies intent, plans what
data a question needs, fetches only permission-approved data through its own
product connectors, and answers in a conversational session. Its grounding comes
from **its** connectors, not from context a caller supplies.

```
Browser → server-php (CodeIgniter 4.7) → worker (Node/TS, HMAC-signed)
                │                              │
                └─ validates ses_key           └─ intent → plan → permission
                   @ my.aicountly.com             → fetch → prompt → model
```

## What Notes can use today

### `POST /api/speech/transcribe` — usable now

The one endpoint that fits a Notes use case directly, and the answer to the
"where does transcription come from" question left open in
`server-php/src/Domain/Jobs`.

| | |
|---|---|
| Auth | `Authorization: Bearer <ses_key>` — the caller's, forwarded |
| Input | multipart field `audio`, **or** JSON `{ audio_base64, mime_type, language? }` |
| Limit | 10 MB |
| Success | `{"status": 1, "data": {"text": "…"}}` |
| Failure | `{"status": 0, "message": "…", "detail"?: "…"}` — 503 unconfigured, 400 unreadable, 413 too large, 502 upstream |

Two things to carry into the Notes adapter:

- **The envelope is `status: 1`, not `success: true`.** That is the AICOUNTLY
  portal convention, which `Portal::validateSesKey` already speaks; Notes' own
  `{success, data}` envelope is for its own clients and must not be assumed of
  a sibling.
- **10 MB is a chat-composer limit, not a meeting limit.** It suits a voice
  note; an hour of meeting audio will not fit and needs chunking or a different
  provider. Treating this as general-purpose transcription would fail on exactly
  the recordings meeting notes exist for.

## What Notes cannot use as designed

`NotesAIService` and `HttpPulseProvider` were written to POST a `PromptBundle`
(system instructions and untrusted note content kept apart) to a configured
`PULSE_API_URL` and receive a completion with citations. **No such endpoint
exists.** The nearest surfaces are:

| Surface | Why it does not fit |
|---|---|
| `POST /api/sessions` + `POST /api/sessions/{id}/messages` | SSE chat. Grounds answers in Pulse's own connectors, and would file every "summarise this note" into a user's Pulse chat history |
| `POST /orchestrate` on the worker | Internal only, HMAC-signed with `PULSE_WORKER_SECRET`, on a private port. Not a cross-product API |
| `POST /api/internal/worker/fetch` | Reserved, answers 501 |

So the Pulse flag stays **off**, and the disabled state Notes already ships is
the honest one. What it is waiting on is a decision, not configuration.

## The registration gap

Pulse proxies these products, re-validating the ses_key and running its
`PermissionGuard` before relaying:

```
manage · books · docs · contacts · calendar · auditor · fr · secretarial · hrms
```

**`notes` is not among them** (`server-php/app/Config/Routes.php`, the
`Api\Proxy\*ProxyController` group). Until it is, Pulse cannot read Notes data,
so "what did I discuss with ABC?" asked *in Pulse* cannot reach a note.

That direction is worth having and Notes is ready for it: `GET /api/search/notes`
and `NotesSearchService::retrieveForAi()` already permission-filter before
ranking, exclude trashed and private notes, and return citable chunks. Adding a
`NotesProxyController` to Pulse plus a connector row is the work.

## Two directions, and they are not the same

Worth separating, because conflating them is what produced the wrong adapter:

1. **Notes asks Pulse** — "summarise this selection", "extract the actions".
   Wants a model, over context Notes supplies, with citations back to the note.
   Pulse has no endpoint for this. Options: add one to Pulse (an authenticated,
   context-in/answer-out route, which is roughly its worker's `/orchestrate`
   made public and permission-checked); or point `PULSE_API_URL` at whatever
   model gateway Pulse itself uses, and accept that Notes then bypasses Pulse's
   audit and usage logging.
2. **Pulse asks Notes** — "what did I discuss with ABC?". Wants Notes registered
   as a Pulse connector. Notes' side is built.

The second is straightforwardly additive. The first needs a product decision
about where Notes' inline AI actually runs.

## Context parameters

Pulse carries company context as **`cmp_id` / `fy_id` / `bo_id` query
parameters**, resolved through `GET /api/context/resolve`.

Notes derives its tenant from the `validatesession` response instead
(`SessionGuard::identityFromPortal`). Both are defensible, but they are not the
same thing, and a Notes↔Pulse call must pass `cmp_id` explicitly rather than
assume the session implies it. This is the detail most likely to produce a
"works for me, empty for them" bug once the two are wired.

## What to do next

1. Point Notes' transcription adapter at `POST /api/speech/transcribe`, match the
   `status: 1` envelope, and document the 10 MB ceiling. Then
   `NOTES_TRANSCRIPTION_ENABLED` becomes a real switch.
2. Decide where Notes' inline AI runs (direction 1 above). Until then the Pulse
   flag stays off and the UI keeps saying so.
3. Add a `notes` proxy and connector to Pulse (direction 2). Notes' retrieval
   side needs no change.
