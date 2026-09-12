# Implementation status

What is built, what was found by the final audit, and what is still open. Read
it as the honest inventory: a feature listed here as working has tests behind
it, and everything known to be wrong is in [Open findings](#open-findings)
rather than left for someone to discover.

**Last audited:** 2026-09-12, against commit history on
`claude/aicountly-notes-saas-ra5si9`.

## Verification at the time of writing

| Suite | Result |
|---|---|
| Backend (`server-php/tests/run.php`) | 557 tests, 2544 assertions, 0 failures |
| Frontend unit (`vitest`) | 44 files, 393 tests |
| Browser (`playwright`) | 16 tests, desktop + phone viewports |
| Types (`tsc -b`) | clean |
| Migrations | apply from an empty database; `0008_pgvector` records itself *skipped* where the extension is absent |

## What works

Capture, organise, search, act and recall are implemented end to end: notes as
structured ProseMirror documents (not an HTML blob), notebooks, tags, smart
folders, templates, checklists and actions, reminders with recurrence,
attachments with OCR/transcription hooks, full-text search with weighted
ranking, version history, sharing with roles, comments, activity, trash with
retention, export, an offline-first client with a sync ledger, and a PWA shell.

Feature flags default **off**, and a flag that is off removes its control
rather than showing one that fails. The four sibling integrations — Drive,
Pulse, Calendar, Contacts, Connect — resolve their hosts from this
deployment's own hostname, the way Pulse's `ProductApiResolver` does.

## The final audit

Nine dimensions were read by independent agents — authorisation and
multi-tenancy, SQL and migrations, web security, sibling integrations, offline
and sync, the document model and editor, frontend states and accessibility,
honesty of the product surface, and performance. Every finding was then given
to a separate agent whose job was to **refute** it by walking the path from a
request to the bad outcome. Findings that could not be refuted are below.

Of the 58 agents, 15 verifiers did not finish (a usage limit). Their findings
are listed separately as **unverified** — they are leads, not conclusions.

### Fixed

| What | Where |
|---|---|
| One person's unsent notes uploaded into the next person's account after a user switch | `localNoteStore.ensureOwner` |
| Coalescing two offline edits discarded the first — write a paragraph, rename the note, the paragraph never existed | `syncQueue.enqueue` |
| Autosave adopted a collaborator's version number, so the optimistic lock passed and their edit was silently overwritten | `useAutosave` |
| A conflicted draft was destroyed by opening another note, though the banner promises it is kept | `useAutosave` |
| A false conflict that halted autosave when returning to a lower-versioned note | `useAutosave` |
| A stale cached body inheriting a fresh version number, overwriting a collaborator with no 409 | `localNoteStore.mergeFromServer` |
| A conflict discarded unreported when a later operation found the network gone | `syncEngine.drainQueue` |
| A repeatedly failing change deleted while the UI said it was still on the device | `syncEngine.drainQueue` |
| The offline queue bypassing the idempotency ledger its own docblock described | `syncEngine.applyOperation` |
| Anyone could claim another user's `connect_meeting_id` and receive their call transcript | `MeetingService`, migration `0009` |
| A meeting id released while its note sat in the Trash | `MeetingService` |
| Drive: aborting a finalize whose answer was lost **deleted the user's uploaded file** | `DriveDocumentService::store` |
| Drive: no stored file could be opened from the SPA (bucket CORS lists only `drive.aicountly.com`) | `AttachmentsController::download` |
| Drive: a detached upload's bytes stayed in Drive for ever | `AttachmentService::driveDisposition` |
| HEIC photos marked "corrupt" instead of skipped | `ThumbnailHandler` |
| `.env.example` naming `NOTES_OCR_URL`, which nothing reads | `server-php/.env.example` |
| Every uploaded image rendered as a broken icon: the document stores a Bearer-only URL and an `<img>` sends no such header | `extensions/image.ts`, `useImageSrcLoader` |
| The notebook picker flattened the shared `['notebooks']` cache, so the sidebar tree lost its children — or the picker lost its nesting, depending which mounted first | `useSearch.ts` |
| Attachment links in the Info panel opened a 401 JSON envelope rather than the file | `NoteInfoPanel.tsx` |
| The Share dialog invited by email; the API stored the string as a user id and the grant matched nobody | `ShareDialog.tsx`, `ShareService.php` |
| Duplicating a note shared with you 404'd about a notebook you were never told about | `NotesService::duplicate` |
| `GET /notes` paged every sort with an `(updated_at, id)` keyset, repeating and skipping rows — and truncated the cursor to milliseconds, skipping notes written in the same millisecond even on the default sort | `NoteRepository.php` |
| Two concurrent saves could both succeed, the second overwriting the first with no conflict | `NotesService::update` |
| `note_embeddings` had no writer, so semantic search could only ever answer `keyword_fallback` | `EmbeddingHandler`, migration `0010` |
| Offline pin/archive/favourite was queued but never written to the device cache, so it visibly undid itself | `useNotes.ts` |
| The service worker cached any navigation response — a 5xx error page included — as the offline shell | `sw.js` |
| `extractText` joined text nodes with a space, indexing a bolded word as `Ai count ly` | `NoteDocument.php` |
| Voice recording and scanning were hidden behind flags the server never required to accept a file | `QuickCapture.tsx` |
| Help described a tag field that existed nowhere — a note's tags could be read and not changed | `NoteInfoPanel.tsx`, `HelpPage.tsx` |
| A long note could make **every** write to itself fail: `search_vector` is GENERATED and `to_tsvector` refuses input over 1,048,575 bytes, so past that the note could not be saved, renamed, pinned or trashed | `NoteDocument::boundForIndexing` |
| The sidebar Trash badge counted other people's trashed notes that the Trash screen then refused to show | `NoteRepository::sidebarCounts` |
| The link dialog accepted `/`-relative hrefs that the sanitiser deletes on save — the text stayed, the link vanished, nothing said why | `EditorDialogs.tsx` |

### Open findings

None. Every finding the verify pass confirmed has been fixed, and each one has
a test that fails without its fix. What is left is the unverified list below.

### The audit's leads, verified and closed

The fourteen leads the audit could not verify — its verifiers ran out of quota
mid-run — were checked against the code by hand. Three did not survive, two had
already been closed by other work, and the remaining nine are fixed:

| What was wrong | Where |
|---|---|
| **A note could not be shared with anyone.** `ShareDialog` was complete, styled and tested, and nothing rendered it; the note menu's "Share…" opened the OS share sheet, which passes a URL to someone the server will refuse. It is mounted now, from the note menu and from a control in the editor header. | `NoteCard.tsx`, `NoteEditorPane.tsx` |
| **The attachments surface was dead code.** `AttachmentList` — upload, capture, Drive linking, previews, removal — was rendered nowhere. It now sits under the document, where an attachment belongs. | `NoteEditorPane.tsx` |
| **Every autosave refetched the note being typed into.** `['notes']` is a prefix of `['notes','detail',id]`, so invalidating it discarded the fresh copy the mutation had just been handed. Lists and counts are invalidated on their own key. | `useNotes.ts`, `queryClient.ts` |
| **A download held the whole file in memory.** `readfile()` streams; `ob_start()` around it to measure `Content-Length` undid that, so a 25 MB attachment was 25 MB of PHP memory. The length comes from the row and the body is written as it is read. | `Response::stream`, `AttachmentsController` |
| **The service worker applied a new build without asking**, contradicting both the docs and the app's own "Update available" prompt. `install` no longer calls `skipWaiting()`, so the prompt has a waiting worker to act on. | `sw.js` |
| **`realtime` was a flag with nothing behind it** — no WebSocket, no polling, nothing reading it, and Settings offering to switch on "edits and presence as they happen". Removed. `canvas` stays: it is enforced server-side and gates real controls. | `Features.php`, `features.ts` |
| **Local development could not reach the API by following the README.** The documented copy wrote `.env` to the repo root; Vite reads `web/`. | `README.md` |
| **The README promised two editor triggers that do not exist** — `[[` and `#`. The real ones are `/` and `@`, and tags are added from the info panel. | `README.md` |
| **The PWA "New checklist" shortcut made an ordinary note.** `?type=checklist` is read now. | `NoteEditorPane.tsx` |

### Leads that did not survive

- **The documented cron does what it says.** `worker.php` runs `maintenance()`
  (trash retention, rate-limit sweep, session expiry, stuck-job reaping) *and*
  `run()`, which drains the processing queue — OCR, transcription, thumbnails,
  text extraction and the rest.
- **The transcription flag's "off" text is accurate.** "Audio is stored and
  played back, but not transcribed" is exactly what happens: the upload is
  accepted regardless and the job reports `skipped`.
- **Every documentation link resolves.** All twelve markdown links in the
  README and `docs/` point at files that exist.

Two more were real when the audit ran and have since been fixed by other work
in this branch: the notebook query-key collision, and semantic search being
documented as a pgvector feature while nothing wrote an embedding.

## Known limitations, by design

- **Audio and video cannot be stored in Drive.** Drive's session-create MIME
  allowlist has no `audio/` or `video/` prefix, so a deployment with Drive on
  cannot attach a recording. Closing it is a one-line change in
  `drive-react-app`, which its §29 anticipates — not something Notes can do,
  and not something to work around by lying about a file's type.
- **Nothing is derived from a Drive-stored attachment.** Drive is reached on
  the caller's own ses_key and a background worker has none.
- **A note purged from Trash by the retention job leaves its Drive documents
  behind**, for the same reason: no request, no session.
- **Drive still lists Notes as *Future*.** Recording it as *Implemented,
  opt-in* is a change in `drive-react-app`, not here.
- **Semantic search and inline AI need a model gateway** that is not part of
  this repository. Both flags stay off until one is configured — and the flag
  now genuinely requires it: `Features::REQUIRES_ENV` lists `PULSE_API_URL`
  against semantic search, so switching it on without a gateway leaves the
  feature off rather than half on. With one configured, notes are chunked and
  embedded by the `note.embedding` job as they are saved.
