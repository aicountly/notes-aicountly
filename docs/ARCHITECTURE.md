# Aicountly Notes — architecture

> Capture anything. Find everything. Act on what matters.

Notes is the knowledge layer of the AICOUNTLY suite. It has to stay as fast and
as simple as Google Keep at the surface while carrying a structure underneath
that can grow into search, meeting intelligence, collaboration and Pulse without
being rebuilt. Everything below follows from that tension.

## The five layers

The code is organised around the path a thought takes through the product, not
around technical tiers:

| Layer | Frontend | Backend |
|---|---|---|
| **Capture** | `features/capture`, `features/notes/components/QuickCapture` | `POST /api/capture`, `NotesService::create` |
| **Editor** | `features/editor` (Tiptap) | `Domain/Notes/NoteDocument` |
| **Knowledge** | `features/search`, `features/collaboration/BacklinksPanel` | `Domain/Search`, `Domain/Links` |
| **Collaboration** | `features/collaboration` | `Domain/Collaboration` |
| **Pulse** | `features/pulse` | `Domain/Ai` |

## Why this stack

The repository already had a shape, and it was a working one: a React + Vite SPA
and a **plain-PHP API with no dependencies**, both deployed to cPanel by `rsync`
with a `php -l` sweep in CI and no build step on the server.

Introducing a framework would have meant either committing a `vendor/` directory
to the repository or adding a `composer install` to a deploy that has nowhere to
run one. So the API stays plain PHP 8.4 and gains structure instead of
dependencies: a 30-line PSR-4 autoloader, a router, and a service layer. The
constraint bought simplicity — there is no container, no magic, and the whole
request path is readable end to end.

The database is **PostgreSQL specifically**, not "a database". Three features
depend on things only it offers, and all three are core rather than incidental:
`jsonb` for the note document, a generated `tsvector` with a GIN index for
search, and pgvector for semantic retrieval.

## The note is a document, not a blob

The single most consequential decision here. A note is a **ProseMirror JSON
tree** in a `jsonb` column, never one HTML field.

```json
{
  "type": "doc",
  "content": [
    { "type": "heading", "attrs": { "level": 1, "blockId": "…" },
      "content": [{ "type": "text", "text": "Meeting with ABC Pvt Ltd" }] },
    { "type": "paragraph", "attrs": { "blockId": "…" },
      "content": [{ "type": "text", "text": "Discussed GST reconciliation." }] }
  ]
}
```

Because the structure is typed, the server can *read* the note. `NoteDocument`
derives from one document: the plain text that feeds search, the checklist items
that become trackable actions, the `[[wiki links]]` that become rows in
`note_links`, the `@mentions` that become entity links, and the word count. None
of that is possible against an HTML string without parsing markup and trusting
the client's summary of its own content.

Every block node carries a **`blockId`** (a UUID, minted by the editor and
enforced by the server). That id is what lets a comment stay anchored, a due date
stay attached to a checklist line after it is reworded, and a backlink scroll to
the right paragraph.

`document_schema_version` is stored on every note so the shape can change later
without a migration that has to guess.

### The sanitiser is the security boundary

`NoteDocument::sanitize()` is an **allowlist** — of node types, of marks, of
attributes per node, and of URL schemes. Anything else does not survive. This is
not defence in depth, it is the actual defence: the frontend renders this JSON
into the DOM for every collaborator on the note, so a `javascript:` href or an
`onclick` attribute that got through here would be stored XSS.

## Authorisation is one SQL fragment

The failure that matters most in a notes product is not a broken screen, it is
one person reading another's notes. So there is exactly one place that decides
who may see what: `Domain/Collaboration/NoteAccess::cte()`.

Two independent gates, both required:

1. **A grant** — the caller owns the note, is a member of the note, or is a
   member of a notebook that contains it (cascading to descendant notebooks).
2. **A tenant match** — the note is personal (`tenant_id IS NULL`) or belongs to
   the company the caller is currently acting in. This applies *on top of* the
   grant, so a stale membership row cannot reach across a tenant boundary.

Every read path composes it: list, search, backlinks, smart folders, and the
retrieval that feeds Pulse. Writes go through `NotePermissionService`, which
returns the note and the caller's effective role together. Controllers never
assemble an ownership check of their own.

Denial is reported as **404, not 403**, when the caller cannot see the note at
all — otherwise probing ids tells you which notes exist.

## Saving

Two rules, and the rest follows.

**A save never silently loses writing.** Every content edit carries the version
the client last saw. A stale version is answered with `409 VERSION_CONFLICT`
carrying the server's current note, so the client can offer a recovery path
rather than discarding one side. Metadata toggles (pin, colour, notebook) are
*not* version-checked — pinning from a stale list should not fail.

**A note appears before the network does.** The client generates the note's UUID,
renders the editor immediately, and sends the same id to the server. Replaying
that create — after a flaky reconnect, or from the offline queue — cannot produce
a second note.

Version history is **checkpointed, not per-keystroke**: an unchanged document
writes nothing, consecutive autosaves by one author inside a ten-minute window
rewrite one entry, and an explicit checkpoint (manual save, restore, import) is
never coalesced away.

## Attachments live somewhere else

A note is a document; a file is not. `note_attachments` is a join table — the
note↔file relationship, the metadata seen at attach time, and what has been
derived from the file — and never the bytes.

Where the bytes go is decided in one place,
`DriveAttachmentService::defaultStore()`: **AICOUNTLY Drive** when it is switched
on, the local disk otherwise. Drive is the suite's storage platform, not an
object store this API writes to, and the difference shapes the code. Drive hands
out a presigned S3 URL, the bytes go **straight to the object store**, and Drive
is then told to scan and promote them — four calls, one of which does not go to
Drive at all. Notes runs that sequence in Drive's *proxy* mode, forwarding the
caller's own session so Drive enforces its own permissions; the browser never
talks to Drive.

Two consequences worth carrying in your head. Reading follows the attachment
row's `storage_provider`, never the current flag, so switching Drive on changes
where *new* files go and nothing else. And a Drive download is a `302` to a
short-lived presigned URL rather than bytes streamed through PHP — the request
has already been authorised twice by then.

[DRIVE_INTEGRATION.md](DRIVE_INTEGRATION.md) has the sequence, the key shapes,
the failure semantics, and what is not built.

## Offline

`localStorage` was the wrong tool: synchronous (so it blocks the editor's frame),
string-only, and a few megabytes. Notes uses **IndexedDB** through a small
hand-rolled wrapper.

- `LocalNoteStore` mirrors the notes the user has listed or opened.
- `SyncQueue` holds mutations made offline. Each carries a client-generated
  operation UUID, so replaying the queue is idempotent, and successive edits to
  one note coalesce into the latest.
- `SyncEngine` drains the queue on reconnect, on tab focus, and on a slow timer
  (the `online` event is unreliable behind a captive portal).

A note with unsent changes is flagged `dirty`, and a background refresh will not
overwrite it. **Conflicts are never resolved automatically** — the user is shown
both sides.

The service worker caches the **shell only**. Note content stays in IndexedDB,
where it can be searched and edited; a Cache Storage entry can do neither, and it
would survive sign-out and be readable by the next person to use the browser.

## Feature flags, and honesty

Every capability that depends on something outside this repository — Pulse,
Drive, Calendar, Contacts, Connect, OCR, transcription, pgvector, realtime,
canvas, E2E private notes — is behind a flag in `Features.php` and **defaults to
off**. A flag also stays off when its dependency is unconfigured, so switching on
`NOTES_AI_ENABLED` without a `PULSE_API_URL` does not produce a UI full of
buttons that fail on click.

An endpoint behind an off flag answers `503 FEATURE_DISABLED`. The frontend reads
the same flags from `GET /api/config` and **hides or visibly disables** the
control. There are no "Coming soon" placeholders anywhere in the product: an
unconfigured deployment looks like a smaller product, not a broken one.

## What is deliberately not built

Stated plainly, because architecture that is *ready for* something is not the
same as having it:

- **Real-time collaborative editing.** The document format is ProseMirror JSON
  and the service boundaries are CRDT-shaped, so Yjs can be added without
  changing the storage model. There is no realtime server, and no custom OT was
  invented in its place. The flag stays off.
- **Infinite canvas.** `note_type = 'canvas'` and its storage exist; the canvas
  editor does not.
- **End-to-end encrypted private notes.** The model distinguishes `standard` from
  `private`, and the server refuses to index, embed, derive from or share a
  `private` note. Client-side key management is not implemented, so the flag is
  off — and ordinary server-side storage is never *labelled* end-to-end
  encrypted, which would be a lie the user would act on.

See `IMPLEMENTATION_STATUS.md` for the full, current list.
