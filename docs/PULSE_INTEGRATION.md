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

### `POST /api/speech/transcribe` — the right shape, the wrong lifetime

This looked like the answer to "where does transcription come from". It is not,
and the reason is worth writing down because the shape check passes and the
integration still cannot work.

`SpeechController::transcribe` runs inside `withAuth()`, which requires a **live
`ses_key`** validated against `my.aicountly.com`. Notes transcribes in a
**background worker**, minutes or hours after the upload — by design, because an
upload must not block on a model. By then the user's `ses_key` is long gone: it
lives ~15 minutes, in memory only, and Notes deliberately never persists it (see
[SECURITY.md](SECURITY.md)). There is no credential the worker could present.

The three ways out, and why only the last one is right:

| | |
|---|---|
| Transcribe synchronously during upload, while the key is live | Puts a model call in the upload request — the thing the job queue exists to prevent — and still hits the 10 MB ceiling |
| Give Notes a stored service credential for Pulse | Breaks the model the whole suite runs on: forward the caller's key so the sibling enforces *its* permissions, never a shared token |
| Pulse adds a signed service-to-service route | Pulse already does exactly this for its own worker (`/api/internal/worker/*`, HMAC with `PULSE_WORKER_SECRET`). The same shape, opened to a sibling, is what background transcription needs |

So transcription stays on Notes' own `RemoteEngine` adapter with a configured
endpoint, and `NOTES_TRANSCRIPTION_ENABLED` stays off until one exists. The job
is marked `skipped`, not failed, and is not retried.

The endpoint's contract, for whenever the auth question is settled:

| | |
|---|---|
| Auth | `Authorization: Bearer <ses_key>` — the caller's, forwarded |
| Input | multipart field `audio`, **or** JSON `{ audio_base64, mime_type, language? }` |
| Limit | 10 MB |
| Success | `{"status": 1, "data": {"text": "…"}}` |
| Failure | `{"status": 0, "message": "…", "detail"?: "…"}` — 503 unconfigured, 400 unreadable, 413 too large, 502 upstream |

Two things to carry into the Notes adapter if it is ever reachable:

- **The envelope is `status: 1`, not `success: true`.** That is the AICOUNTLY
  portal convention, which `Portal::validateSesKey` already speaks; Notes' own
  `{success, data}` envelope is for its own clients and must not be assumed of
  a sibling. (`RemoteEngine` already unwraps a `data` object, so this half
  happens to line up.)
- **10 MB is a chat-composer limit, not a meeting limit.** It suits a voice
  note; an hour of meeting audio will not fit and needs chunking or a different
  provider. Treating this as general-purpose transcription would fail on exactly
  the recordings meeting notes exist for.

**The lesson worth keeping:** matching request and response shapes is not the
same as an integration working. The auth lifetime is part of the contract, and
it is the part that does not show up in a route map.

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

## Sibling API origins — an ecosystem convention Notes should adopt

Pulse does not require a URL per sibling product. `App\Services\ProductApiResolver`
derives one:

1. `{PRODUCT}_API_ORIGIN`, if set;
2. otherwise from the request host — a sandbox host gives
   `{product}.gh.aicountly.com`, anything else `{product}.aicountly.com`.

Its sandbox test is character-for-character the one Notes already ships in
`web/src/auth/hostnames.ts` (`^[a-z0-9-]+\.gh\.aicountly\.com$` and
`^gh-[a-z0-9-]+\.aicountly\.com$`), which came from books-react-app. The
convention is settled; Notes just implements half of it.

Notes currently makes `DRIVE_API_URL`, `CALENDAR_API_URL`, `CONTACTS_API_URL`
and `CONNECT_API_URL` **required** before their flags can switch on
(`Features::REQUIRES_ENV`). That was the right instinct — a flag on with nothing
behind it produces controls that fail on click — but it is stricter than the
ecosystem needs, and it makes every deployment carry four URLs that are already
implied by its own hostname.

The change: keep the flag as the explicit gate, make the URL optional, and derive
the origin from the host when it is unset. `NOTES_DRIVE_ENABLED=true` alone then
works in both environments, exactly as it does for every other product, while
turning something on still remains a deliberate act.

## What to do next

1. Point Notes' transcription adapter at `POST /api/speech/transcribe`, match the
   `status: 1` envelope, and document the 10 MB ceiling. Then
   `NOTES_TRANSCRIPTION_ENABLED` becomes a real switch.
2. Decide where Notes' inline AI runs (direction 1 above). Until then the Pulse
   flag stays off and the UI keeps saying so.
3. Add a `notes` proxy and connector to Pulse (direction 2). Notes' retrieval
   side needs no change.
