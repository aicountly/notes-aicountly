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
| Backend (`server-php/tests/run.php`) | 536 tests, 2465 assertions, 0 failures |
| Frontend unit (`vitest`) | 40 files, 376 tests |
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

### Open findings

Confirmed by the verify pass and **not yet fixed**. Ordered by severity. Both
findings the audit rated *high* have since been closed and moved above.

| Sev | Finding | Where |
|---|---|---|
| Medium | Attachment links in the Info panel open a 401 JSON envelope rather than the file | `NoteInfoPanel.tsx:166` |
| Medium | The Share dialog invites by email address; the API stores the string as a user id and the grant never matches anyone | `ShareDialog.tsx:165` |
| Medium | Duplicating a note shared with you 404s about a notebook you were never told about | `NotesService.php:449` |
| Medium | `GET /notes` returns an `(updated_at, id)` keyset cursor for sorts not ordered by `updated_at`, so paging repeats and skips rows | `NoteRepository.php:74` |
| Medium | The optimistic-concurrency check reads the version outside the transaction with no version predicate on the UPDATE, so two concurrent saves can both succeed | `NotesService.php:207` |
| Medium | `note_embeddings` has no writer, so semantic search can only ever answer `keyword_fallback` while reporting itself enabled | `NotesSearchService.php:383` |
| Medium | Offline pin/archive/favourite is queued but not written to the device cache, so the change visibly undoes itself | `useNotes.ts:299` |
| Medium | The service worker caches any navigation response — including a 5xx error page — as the offline shell | `sw.js:62` |
| Medium | `extractText` joins sibling text nodes with a space, so a word split by a mark boundary is indexed with a space inside it | `NoteDocument.php:373` |
| Medium | Voice recording and document scanning are hidden behind flags the server does not require | `QuickCapture.tsx:245` |
| Medium | Help documents a tag field on a note; no screen adds or removes a tag on a note | `HelpPage.tsx:160` |
| Low | The sidebar Trash badge counts other people's trashed notes that the Trash list then refuses to show | `NoteRepository.php:296` |
| Low | `notes.extracted_text` is uncapped while `search_vector` is a generated `tsvector`, so a document under the 4 MB limit can make every write to that note fail | `0001_core_notes.sql:96` |
| Low | The link dialog accepts `/`-relative hrefs that the server silently deletes on save | `EditorDialogs.tsx:16` |

### Unverified leads

The verifier for each of these ran out of quota, so they have **not** been
confirmed against the code. Treat them as the next session's starting list,
not as defects.

- The attachments UI may be unmounted — nothing renders `AttachmentBlock`/`AttachmentList`
- Nothing may render the Share dialog, despite sharing being role-gated throughout
- `README` promises `[[` and `#` editor triggers
- `OFFLINE_SYNC.md` promises service-worker behaviour that may not match `sw.js`
- The `realtime` flag may have no implementation behind it
- The documented cron command may not process what the docs say
- The PWA "New checklist" shortcut may ignore its parameter
- The transcription flag's "off" explanation may be inaccurate
- `README`/`ARCHITECTURE` may point at documents that do not exist
- The documented local-dev step may write `.env` incorrectly
- Attachment download buffers the whole file into memory
- Every autosave may invalidate the whole `['notes']` query key
- `useNotebookOptions` and `useNotebooks` may share a key with different shapes
- Semantic search's pgvector path may be documented beyond what it does

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
  this repository. Both flags stay off until one is configured.
