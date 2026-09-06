# Drive integration

AICOUNTLY Drive owns files across the suite. Notes borrows them: it stores the
relationship between a note and a file, and what has been derived from that file,
but never the bytes.

**Status: adapter-ready.** The Notes side is implemented and tested. It is
switched off until `NOTES_DRIVE_ENABLED=true` and `DRIVE_API_URL` are both set,
because a flag on with no URL would only produce controls that fail when pressed.
The request/response mapping is written against Drive's expected shape; the exact
paths are marked in `DriveAttachmentService` as needing confirmation against
Drive's own API before the flag is turned on.

## What each side owns

| Notes owns | Drive owns |
|---|---|
| the note↔file relationship | the bytes |
| filename, MIME, size, checksum as seen at attach time | the file's own lifecycle and versions |
| extracted text (OCR, PDF) and transcripts | permissions on the file itself |
| processing state and errors | sharing, folders, quota |
| the block the file is rendered in | |

Nothing here is a second object store. `note_attachments` is a join table with
metadata.

## Where bytes go

`DriveAttachmentService::defaultStore()` is the only place that decides:

- **Drive configured** → `DriveObjectStore`
- **otherwise** → `LocalObjectStore`, writing under `NOTES_STORAGE_PATH`

So no caller branches on a feature flag to save a file.

Reading is deliberately *not* symmetrical. `storeFor($provider)` is driven by the
attachment row's `storage_provider`, never by the current flag — a file written
to disk before Drive was switched on is still on that disk, and asking Drive for
it would 404 a file the user can see in their note. Turning Drive on changes
where *new* files go and nothing else.

## Attaching a file the user already has

`POST /api/notes/{id}/attachments/link-drive` attaches an existing Drive file by
id **without copying its bytes**.

The caller's identity goes with the request to Drive, and that is the security
property rather than a convenience: Drive re-checks that *this user* may see
*that file*. A Drive file id is not a capability on its own, so Notes never
treats one as proof of access — otherwise pasting someone else's file id into
this endpoint would attach a file the caller cannot open.

## The local fallback

`LocalObjectStore` exists so an unconfigured deployment still works:

- keys are `YYYY/MM/<32 hex chars>` — non-guessable, and not derived from the
  filename;
- writes go to a `.part` file and are renamed into place, so a failed upload
  never leaves a half-written object that looks complete;
- the store root gets a deny-all `.htaccess` on creation, covering both Apache
  2.2 (`Order`/`Deny`) and 2.4 (`Require`) — necessary because on cPanel the
  `api/` directory sits inside the document root;
- directories are `0700`.

Better still, put `NOTES_STORAGE_PATH` outside the document root entirely. See
[DEPLOYMENT.md](DEPLOYMENT.md#attachment-storage).

## Downloads

Bytes are never served from a guessable URL. `GET
/api/notes/{id}/attachments/{attachmentId}/content` re-checks note permission on
**every** request and streams with `Content-Disposition: attachment` and
`X-Content-Type-Options: nosniff`. Uploaded HTML is never rendered as trusted
content.

`signedUrl()` exists on the store interface for the day Drive can issue one, so
large media can bypass this API without redesigning the endpoint.

## Processing

An upload does not wait for processing — a thumbnail must not stand between
someone and their note. The request stores the bytes, records the attachment as
`processing_status: queued`, and returns.

`bin/worker.php` then runs the job. Text extraction and thumbnails happen
locally; OCR and transcription post to a configured service and are gated on
their own flags. **With a flag off, the job is marked `skipped`, not `failed`,
and is not retried** — there is nothing to retry until the deployment gains that
capability.

Whatever text a job produces is appended to `notes.derived_text`, which feeds the
search vector at weight C. It is kept apart from `extracted_text` so re-saving a
note never discards OCR output and re-processing an attachment never overwrites
what the user typed.

## Failure

Drive is a service, not a mirror, so a failure is treated as retryable: the API
answers `502 UPSTREAM_UNAVAILABLE`, the attachment keeps its row, and the job is
retried with backoff. Response bodies are never logged.

## Turning it on

1. Confirm the paths in `DriveAttachmentService` against Drive's current API.
2. Set `DRIVE_API_URL` and `NOTES_DRIVE_ENABLED=true` in `api/.env`.
3. `GET /api/config` should now report `drive: true`, and the attachment UI will
   offer "Choose from Drive".

Existing local attachments keep working, and keep being served from disk.
