# Security

Notes holds private thinking — meeting minutes, client discussions, half-formed
ideas, scanned documents. The consequential failure is not a broken screen, it is
someone reading what is not theirs. This document is what was actually built, not
a checklist.

## Authentication

Nothing here mints, signs or stores a credential. The AICOUNTLY portal
(`my.aicountly.com`) owns every token; this API only asks it whether a session is
live. See `docs/auth/AICOUNTLY_AUTH_WORKFLOW.md` for the flow.

| Token | Lifetime | Stored | Used for |
|---|---|---|---|
| `auth_token` | long-lived | `localStorage` + a `.aicountly.com` cookie | minting a `ses_key` |
| `ses_key` | ~15 min | **memory only** | `Authorization: Bearer` on this API |

The `ses_key` must never reach `localStorage` or `sessionStorage`; it lives in a
module variable that dies with the page.

**The session cache.** Validating a `ses_key` means a round trip to the portal,
and doing that on every request would put the portal in the critical path of
every autosave. A successful validation is cached in `api_sessions` for 60
seconds by default. Two properties keep that honest: the key is stored as a
**SHA-256 hash** (a dump of the table hands nobody a usable session), and the TTL
is short and capped at 300s, so revoking a session at the portal takes effect in
seconds. A portal outage denies access rather than granting it.

## Authorisation

One place answers "may they?": `NotePermissionService`, over the SQL in
`NoteAccess`. Two gates, both required — a grant, and a tenant match — described
in `ARCHITECTURE.md`.

| Role | View | Comment | Edit body | Share / delete |
|---|---|---|---|---|
| Owner | ✓ | ✓ | ✓ | ✓ |
| Editor | ✓ | ✓ | ✓ | — |
| Commenter | ✓ | ✓ | — | — |
| Viewer | ✓ | — | — | — |

Sharing and deleting stay with the owner: an editor who could re-share a note
could widen an audience the owner chose. Only `editor`, `commenter` and `viewer`
can be granted — ownership is not transferable through the share dialog.

Endpoints that take a **child id** (an action, comment, reminder, attachment or
member id) authorise against the parent *note*, not the child. Otherwise a child
id would be a way around note permissions.

A note the caller cannot see is **404**, not 403.

Tests covering all of this live in `server-php/tests/Cases/AuthorizationTest.php`
and are written from the attacker's side: user B tries to read, edit, delete,
list, search and restore user A's notes, across tenants, through notebook
cascades, and through the trash.

## Stored XSS

The note document is client-supplied JSON that the frontend renders into the DOM.
`NoteDocument::sanitize()` is therefore not a nicety:

- **allowlisted** node types, mark types, and attributes per node type;
- URL schemes limited to `http`, `https`, `mailto`, `tel` — `javascript:`,
  `data:` and scheme-relative `//host` are rejected, after stripping whitespace
  and control characters so `java\nscript:` cannot slip through;
- `rel="noopener noreferrer nofollow"` forced on every link, never taken from the
  client;
- control characters stripped from text nodes;
- depth, node-count and byte limits, so a pathological document cannot DoS the
  recursive walks.

An unsafe link loses the *link*, not the text — the user's words survive.

On the frontend, `dangerouslySetInnerHTML` is not used anywhere. Search snippets
are the case that would tempt it: the server returns highlights with a plain-text
marker rather than HTML, and the client parses the marker into `<mark>` elements.

## Uploads

- Bytes never go in Postgres. Drive owns the file where configured; otherwise a
  local object store writes under `NOTES_STORAGE_PATH` with a hashed,
  non-guessable key, and writes a deny-all `.htaccess` into that directory —
  necessary because on cPanel the `api/` folder sits inside the document root.
- MIME type is determined from **content** (`finfo`), not from the client's
  `Content-Type` or the filename extension.
- Size is enforced server-side against `NOTES_MAX_ATTACHMENT_SIZE`.
- Downloads go through an endpoint that re-checks note permission every time and
  serves with `Content-Disposition: attachment` and `X-Content-Type-Options:
  nosniff`. Uploaded HTML is never rendered as trusted content. For a
  Drive-stored file the same check runs and the endpoint then redirects to a
  short-lived presigned URL that Drive issued after its **own** permission check
  — authorised twice, and no bytes through this process.
- Notes never holds object-storage credentials. Drive's backend is the only
  credentialed service in the suite, and this API reaches object storage only
  through URLs Drive signs for one object at a time.

### A presigned URL never carries a session key

The rule, because getting it wrong is quiet and expensive: **an AICOUNTLY session
key is sent to AICOUNTLY origins only.** A presigned upload or download URL points
at the object-storage provider, not at `*.aicountly.com`, and its authority is
already in the URL's signature. A `ses_key` on that request would add nothing and
would hand a live session to a third-party object store, to every proxy in
between, and to their access logs — where URLs are routinely written down.

This is enforced structurally rather than by care. `AicountlyClient::putBytes()`
and `fetchBytes()` are separate methods from `send()`, not a flag on it: they
accept no `ses_key`, build no `Authorization` header, and there is no argument
anyone can pass that turns the header back on. `DriveUploadTest` asserts the
absence. Two supporting rules make it hold:

- **The URL is data, not configuration.** It arrives inside another product's
  JSON response, so it is validated to be `http(s)` before anything is sent to
  it; `file://` or a bare path would turn a confused or compromised sibling into
  a way to make this server read its own disk.
- **Redirects are never followed** on any sibling call, because a redirect would
  carry the session key to whatever host it names.

The same rule is why a presigned GET is handed to the browser rather than fetched
with credentials attached. See
[DRIVE_INTEGRATION.md](DRIVE_INTEGRATION.md#drive-never-receives-the-bytes).

## SQL

Every query uses bound parameters through `Connection::select` / `execute`. There
is no method in the codebase that concatenates a caller's value into SQL.

Smart folders are the interesting case, because a user stores a query. Rules name
a **field** from a fixed vocabulary (`NoteQuery::FIELDS`) and an operator that
field allows; an unknown field or operator is rejected at write time. Values are
bound. Nothing a user stored ever reaches SQL as text.

## Rate limiting

Fixed-window, per user and per bucket, in `RateLimiter`. The buckets exist so the
expensive things can be limited **without limiting typing** — an app that answers
429 while someone is writing has failed at its one job. Note writes are not rate
limited. AI, search, semantic search, uploads, imports and sharing are.

## Logging

`Logger` drops the keys that carry user content — title, body, document,
extracted text, transcripts, prompts, answers, snippets, queries — plus tokens
and secrets. Note content never reaches a log line, and the activity trail
(visible to every collaborator on a note) carries ids, roles and counts only.

Stack traces and exception messages are returned only when `APP_DEBUG=true` and
the environment is not production. Clients get a stable error code and a request
id to quote.

## AI and privacy

Before any context reaches a provider:

1. permission is checked, and retrieval is **permission-filtered before ranking**
   — never retrieved broadly and filtered in the UI;
2. trashed notes are excluded;
3. `private` notes are excluded entirely;
4. tenants are never mixed in one request's context.

**Prompt injection.** Note text, OCR output and clipped pages are untrusted data.
`PromptBundle` keeps system instructions and user content in separate fields, and
the provider sends them in separate message roles — note text is never
concatenated into the instruction string. Retrieved content is delimited and
labelled as data that cannot issue instructions.

**Citations.** Anything answered from the user's own notes returns structured
citations (`note_id`, title, block, snippet). When the model answers without
grounding, the response carries `grounded: false` and the UI says so, rather than
presenting an unsourced claim as if it came from the user's notes.

## Private notes

Two levels are modelled, and the difference is stated honestly rather than
blurred:

- **standard** — stored server-side, eligible for search, indexing and Pulse.
- **private** — the server holds ciphertext it cannot read. It does not index it,
  does not derive links or checklist actions from it, does not embed it, does not
  send it to any AI provider, and does not allow it to be shared.

Client-side key management is **not implemented**, so `NOTES_PRIVATE_NOTES_ENABLED`
is off and a note cannot currently be marked private. Ordinary server-side
storage is never *labelled* end-to-end encrypted — that would be a claim a user
would act on.

## Headers

`server-php/.htaccess` sets `X-Content-Type-Options: nosniff` and `Cache-Control:
no-store` on API responses, and denies any dotfile — without which
`https://notes.aicountly.com/api/.env` would be fetchable, since `api/` lives
inside the document root. The web build's `.htaccess` adds
`Referrer-Policy: strict-origin-when-cross-origin`.

## The portal relay

`/api/global/{path}` forwards a small **allowlist** — `seskey`, `seskey/refresh`,
`refresh_authtoken` — so the SPA never makes a cross-origin call to the portal.
Forwarding arbitrary paths would turn this host into an open proxy for the
portal's whole auth surface, with the portal seeing this server's IP instead of
the caller's, so anything it rate-limits per IP could be driven through here.
Percent-escapes are decoded before matching so `%2e%2e` cannot smuggle a
traversal segment past it.

## Reporting

Security issues in Notes should go to the AICOUNTLY platform team through the
usual internal channel, not to a public issue tracker.
