# Drive integration

AICOUNTLY Drive (`drive.aicountly.com`) is the suite's **storage platform**, not
an object store Notes writes to. It is a PostgreSQL metadata service in front of
S3-compatible buckets: it decides where an object lives, scans it, promotes it,
records it, versions it, and hands out short-lived signed URLs after its own
permission check. Only Drive's backend holds storage credentials — no other
product's server does, and no browser ever does.

Notes is a **storage consumer**: it keeps the relationship between a note and a
file, and what has been derived from that file, and never the bytes.

**Status: adapter-ready.** The Notes side is implemented and covered by
`tests/Cases/DriveUploadTest.php`. It is switched off until
`NOTES_DRIVE_ENABLED=true`; Drive's address is derived from this deployment's own
hostname through `Integrations\SiblingApi`, so `notes.gh.aicountly.com` reaches
`drive.gh.aicountly.com` with nothing configured. `DRIVE_API_ORIGIN` overrides
that if Drive is ever somewhere unusual (the older `DRIVE_API_URL` is still read,
verbatim, for deployments that set it). Drive's own registry still lists Notes as
*Future* — see [What is not done](#what-is-not-done).

Note that Drive's **host** is `drive` while its **`product_code`** is `docs` —
the code goes in request bodies and object keys, the host is where the socket
opens, and `SiblingApi` keeps them apart. Spelling the code into a hostname
gives `docs.aicountly.com`, which resolves nowhere. Notes' own code is `notes`.

Everything below is written against Drive's own repository: the canonical
`drive-react-app/docs/AICOUNTLY_DRIVE_STORAGE_ARCHITECTURE.md` (§4 what Drive
owns, §6 tenancy, §8 key grammar, §9 integration modes, §10 the product
registry, §20 authorization, §21 the upload flow, §29 onboarding),
`docs/UPLOAD_SAVE_FLOW.md`, and the code those describe —
`app/Config/Routes.php`, `app/Services/DocumentService.php`,
`app/Services/ObjectKeyBuilder.php`. Nothing here is written from expectation.

## What each side owns

| Notes owns | Drive owns |
|---|---|
| the note↔file relationship | the bytes, and the object key they live at |
| filename, MIME, size, checksum as seen at attach time | the scan, the verified size, the stored checksum |
| extracted text (OCR, PDF) and transcripts | versions, retention, legal hold, archive |
| processing state and errors | permissions on the file itself, sharing, quota |
| the block the file is rendered in | the audit trail of storage events |

Nothing here is a second object store. `note_attachments` is a join table with
metadata, and Notes never becomes the system of record for a file.

## Notes' onboarding record

Drive's §29 is the contract: onboarding a product is configuration, not a storage
redesign. Notes' record in that form:

| Step | Notes |
|---|---|
| `product_code` | `notes` — lowercase, stable, chosen once, **never renamed** |
| scope | `personal` **and** `company` — a note's `tenant_id` is nullable, so both are real |
| entity | `entity_type = note`, `entity_id` = the note's UUID |
| modules | `attachments`, `voice-notes`, `scans`, `meeting-recordings`, `thumbnails` — the two audio/video ones cannot be used yet, see below |
| integration | **Proxy** (§9) — Notes' backend runs Drive's upload-session sequence on the user's behalf, exactly as Books and HRMS do. The browser never talks to Drive. |
| links | `POST /api/document-links` after finalize, `DELETE /api/document-links/{id}` when a Drive attachment is detached — both best-effort, see [Cross-references](#cross-references) |
| retention | none requested; Notes falls into Drive's `standard` class |

**The key shape needs no change in Drive.** `notes` is not one of the eight
`product_code` values with a branch in `ObjectKeyBuilder` (`books`, `hrms`,
`auditor`, `fr`, `secretarial`, `chat`, `contacts`, `vault`), and it does not
need one: a note is a business record with a UUID, which is exactly what
`entity_type` + `entity_id` express, so Notes lands on the **generic key shape**.
`product_code` is an unvalidated `VARCHAR(64)` on the request and an unrecognised
value falls through to the generic builder — that permissiveness is the property
§29 exists to protect.

**Drive's MIME allowlist does need one, and this is the one place onboarding
Notes is not pure configuration.** §29 says so itself: its table of what requires
a Drive change lists *"new media types → the MIME allowlist"*.
`POST /api/upload-sessions` checks the declared `content_type` through
`DocumentService::assertAllowedContentType()` before it writes a session row,
against `ALLOWED_MIME_PREFIXES`:

```
application/pdf   application/msword   application/vnd.
application/vnd.openxmlformats-officedocument                image/
text/plain        text/csv             application/zip
application/x-zip-compressed           application/octet-stream
```

There is no `audio/` and no `video/`. Notes accepts both — `AttachmentService::KINDS`
lists ten audio types and five video types — so a voice note, a meeting
recording, a `text/markdown` file and an `application/rtf` document are refused
at **step 1** with `UPLOAD_SESSION_FAILED` (HTTP 400), before a byte moves and
before any of the sequence below runs. Images (every type Notes takes starts
`image/`), PDFs, Office and OpenDocument files, plain text, CSV and zips go
through. Until Drive's allowlist gains `audio/` and `video/`, the `voice-notes`
and `meeting-recordings` modules are reserved rather than usable, and a
deployment with Drive on cannot store a recording at all. See
[What is not done](#what-is-not-done).

The keys Drive will write (`ObjectKeyBuilder::buildFinalKeyForSession`):

```
# personal note
tenant/user/{uuid}/product/notes/module/attachments/
  entity/note/{note_uuid}/doc/DOC01234/v/1/{filename}

# company note
tenant/company/{cmp_id}/product/notes/branch/{bo_id}/fy/{fy_id}/module/attachments/
  entity/note/{note_uuid}/doc/DOC01234/v/1/{filename}
```

`fy_id` is in every company key, but it carries no business meaning for Notes — a
note is not FY-scoped. Drive is explicit that such products record the session's
`fy_id` for isolation and consistent key shape only, and must never *filter* on
it (§6).

## Where bytes go

`DriveAttachmentService::defaultStore()` is the only place that decides:

- **Drive switched on** → `DriveObjectStore`
- **otherwise** → `LocalObjectStore`, writing under `NOTES_STORAGE_PATH`

So no caller branches on a feature flag to save a file.

### Drive never receives the bytes

This is the part to understand before reading `DriveDocumentService`, and the
part an earlier version of this document got wrong. A file does not arrive at
Drive by being POSTed to it. Four steps, and only one of them carries the file:

| # | Call | Goes to |
|---|---|---|
| 1 | `POST /api/upload-sessions` | Drive — answers with a presigned PUT into the **quarantine** bucket |
| 2 | `PUT {upload_url}` | **S3 directly.** Not Drive, and with **no `Authorization` header** |
| 3 | `POST /api/upload-sessions/{id}/complete` | Drive — the session is `uploaded` |
| 4 | `POST /api/upload-sessions/{id}/finalize` | Drive — scan, checksum, quarantine → private, `documents` row |

Only after step 4 does a document id exist. That id is what
`note_attachments.storage_key` holds for a Drive-stored file: `allocateKey()`
returns `''`, because Drive builds the object key itself and an address invented
here would be fiction.

**Step 2 is the one that has to be got right twice over.** `upload_url` points at
the object-storage provider — not at `*.aicountly.com` — and the signature is
already *in* the URL. A `ses_key` on that request would buy nothing and would
hand a live AICOUNTLY session to a third-party object store and to every proxy
and access log in between. `AicountlyClient::putBytes()` and `fetchBytes()` are
therefore separate methods from `send()` rather than a flag on it: they take no
`ses_key`, build no `Authorization` header, and there is no argument anyone can
pass that turns the header back on. `DriveUploadTest` asserts its absence. They
also refuse a URL that is not `http(s)`, because that value arrives inside
another product's JSON response — data, not configuration — and `file://` would
turn a confused sibling into a way to make this server read its own disk.

Notes runs this sequence **as the person who asked** — Drive's "proxy"
integration mode (§9), the one Books and HRMS use. The caller's `ses_key` is
forwarded on the three Drive calls, so Drive applies its own permissions rather
than trusting this API's word. There is no service token, which would make every
Notes user as powerful as the integration itself; it also means a cron worker,
which has no session, cannot reach Drive at all. See *Processing* below.

### What Notes tells Drive about a file

| Field | Value |
|---|---|
| `product_code` | `notes` — Notes' entry in §10 of Drive's registry |
| `entity_type` / `entity_id` | `note` / the note's UUID |
| `module_code` | `attachments`, `voice-notes`, `scans`, `meeting-recordings` or `thumbnails` |
| `scope` | `personal` when the note's `tenant_id` is null, `company` when it is set |
| `size_bytes` | on session create — optional to Drive, sent so it can refuse an oversized upload before a byte moves |
| `title`, `size_bytes` | on finalize |
| `sha256` | on finalize — the digest Notes already stores on the attachment row |

Every one of those except the sizes and the hash is written into an S3 key, and
**a key already written is a fact**: Drive validates none of them on the way in,
so a wrong value can never be corrected without copying every object. They are
constants in `Integrations\DriveContext` with that warning on them. Add a module;
never rename one.

`sha256` is optional to Drive and sent anyway. Drive streams the promoted object
once, computes its own digest, compares the declared one against it and rejects a
mismatch — so a truncated PUT that returned 200 is caught at upload rather than
discovered by whoever opens the file next year. The **stored** checksum is always
Drive's own; a hash the uploader chose proves nothing about the bytes Drive
holds. That same pass replaces the declared `size_bytes` with the count it read.

One thing to check on Drive's side before turning this on, because sending the
hash unconditionally has a cost. Drive's hashing pass is itself switchable
(`CHECKSUM_ON_FINALIZE`, default on). With it **off** Drive has nothing to
compare against, and `ObjectChecksumService::verifyDeclared()` treats a declared
hash it cannot check as an error rather than ignoring it — *"checksum
verification was requested but the object could not be hashed"*. Finalize then
throws, the session is marked `failed` and the object is left in quarantine. A
Drive deployment with `CHECKSUM_ON_FINALIZE=0` would therefore reject **every**
Notes upload, not just a corrupted one, and nothing on this side can tell that
apart from any other finalize failure.

The note's UUID is permanent from the moment it is created, so unlike Books —
which uploads against `draft-{id}` and rebinds later — Notes never needs
`POST /api/documents/{id}/rebind`.

### Quarantine, scan, promote

Drive's gate exists so that the *default* state of an uploaded byte is disposable
(§21). Writing straight into the private bucket would make the malicious case the
one where cleanup has to be perfect.

1. Step 1 presigns into the **quarantine** bucket, at
   `incoming/product/notes/tenant/{user|company}/{id}/upload/UPL00042/original/{filename}` —
   the same shape for every product.
2. At finalize, `ScanService` checks metadata: zero-byte files, files over
   `MAX_UPLOAD_BYTES`, blocked executable and script extensions, compound
   extensions such as `.pdf.exe`, and a `Content-Type` that contradicts the
   extension. ClamAV byte scanning runs only where `SCAN_PROVIDER=clamav`; Drive
   documents `stub` as the deployed default and surfaces it as
   `storage.scan_warning` on its `/api/health`. **A clean scan is not a malware
   scan on most deployments today**, which is Drive's own stated gap (§27), and
   it is a reason to keep Notes' own content-sniffing and MIME rules rather than
   deferring to Drive's.
3. `ObjectChecksumService` streams the object once, computing SHA-256 and the
   real byte count.
4. On success Drive copies quarantine → **private**, deletes the quarantine
   object immediately, inserts `documents` + `document_versions` + the owner ACL,
   and sets `scan_status = clean`.
5. On failure the session becomes `failed`, `quarantine_purge_at` is set, and the
   object stays in quarantine until Drive's lifecycle cron removes it. Nothing is
   ever promoted unverified.

### When it fails

Between step 1 and step 4 there is a session, and usually an object sitting in
quarantine. Any failure after step 1 calls `POST /api/upload-sessions/{id}/abort`,
which deletes the quarantine object immediately and marks the session `aborted` —
so a failed upload does not wait for Drive's cron. The abort is best-effort and
never replaces the original error, because the caller needs to hear the failure
they can act on; the cron is the backstop either way.

A rejected scan reaches the user as a **failed attachment**, not as a file that
is "processing". The attachment row records `upload_status: failed` with an empty
`storage_key` and no queued processing. That is deliberate: an upload that
vanished silently is worse than one that says so, a client polling the id learns
that the file is not coming, and re-sending the same id clears the row and
retries rather than handing back the failure as though it were a create. There is
nothing for anyone to clean up by hand.

Reading is deliberately *not* symmetrical. `storeFor($provider)` is driven by the
attachment row's `storage_provider`, never by the current flag — a file written
to disk before Drive was switched on is still on that disk, and asking Drive for
it would 404 a file the user can see in their note. **Turning Drive on changes
where new files go and nothing else.**

## Company context, and personal notes

Drive requires `cmp_id`, `fy_id` and `bo_id` on **every** call.
`SesAuthController::authProduct()` reads each from the query string, then an
`X-AIC-CMP-ID` / `X-AIC-FY-ID` / `X-AIC-BO-ID` header, then the JSON body, and
names the one that is missing: `MISSING_COMPANY_CONTEXT`,
`MISSING_FINANCIAL_YEAR` or `MISSING_BRANCH_CONTEXT`, all HTTP 400. Notes sends
them as query parameters.

- A **company note** uses `scope=company` and the caller's real company context.
  Notes cannot supply it from its own `tenant_id`, which is the portal's company
  **UUID** rather than the numeric `cmp_id` Drive keys on, so the request must
  carry it or the upload is refused here rather than sent for Drive to reject. A
  guessed company would write the file into somebody else's tree.
- A **personal note** carries Drive's documented "no company" sentinel:
  `cmp_id = fy_id = bo_id = "0"`, **all three, literally**. Drive reserves it for
  personal scope and refuses to combine it with `scope=company`. It overrides
  whatever company the user happened to have selected — a personal note is not
  filed under a company.

The scope follows the **note**, not the caller: a note created as a company note
stays one whoever opens it later.

## Attaching a file the user already has

`POST /api/notes/{id}/attachments/link-drive` attaches an existing Drive document
by id **without copying its bytes** — a 200 MB recording already in Drive should
appear on a note instantly and exist once, not twice.

The caller's identity goes with the request to Drive, and that is the security
property rather than a convenience: Drive re-checks that *this user* may see
*that file*. A Drive document id is not a capability on its own, so Notes never
treats one as proof of access — otherwise pasting someone else's file id into
this endpoint would attach a file the caller cannot open. Drive's 403 and 404
come back as one indistinguishable "not found", the same way they do for a note.

`drive_file_id` on the row is what separates the two kinds of Drive attachment. A
file **uploaded through Notes** is a document Notes created and may delete. A
file **linked from Drive** is the user's own: detaching it removes the attachment
row and nothing else, because deleting it would destroy a file they still have in
Drive.

**The name and the type come from the version, not from the document**, and this
is the one field mapping worth spelling out because reading the obvious place
does not work. `DocumentService::formatDocumentRow()` does expose `filename`,
`mime_type` and `checksum_sha256` — but it reads them from `current_filename`,
`current_mime` and `current_checksum`, which are aliases produced by the
`LEFT JOIN document_versions` in the **list** query. The single-document route
does no such join: `getDocumentDetails()` fetches through
`PermissionService::getDocumentForCtx()`, which is `SELECT * FROM documents`, and
the `documents` table has no filename or mime column at all. So
`GET /api/documents/{id}` answers `filename: null` and `mime_type: null` for
every document, always.

What that route does carry is `versions[]` — the `document_versions` rows in
full — so `DriveAttachmentService::file()` reads the entry flagged
`is_current = 1` and takes `filename`, `mime_type`, `size_bytes` and
`checksum_sha256` from there, falling back to the document's own `title` and
`size_bytes` (both real columns, set at finalize). `DriveUploadTest` pins that
shape.

## Cross-references

Step 6 of Drive's §29 asks a product to register the reverse index — the row
that lets Drive answer "what is this file attached to?" from its own side,
rather than every product having to be asked. `document_links` is Drive's
anti-duplication mechanism, and Notes writes to it:

- **After finalize**, `DriveDocumentService::link()` posts
  `{doc_id, product_code: notes, external_record_type: note, external_record_id: <note uuid>}`
  to `POST /api/document-links`. It runs only once the bytes are safely stored,
  and it is **best-effort**: the file exists by then, and failing an upload the
  user completed over a missing cross-reference would be the wrong trade.
  Drive's insert is `ON CONFLICT … DO NOTHING`, so a later retry converges.
- **On detach**, the row is dropped again — `GET /api/document-links` filtered
  by `product_code` and `external_record_id`, then
  `DELETE /api/document-links/{id}` for the one naming this document. Also
  best-effort, and also skipped entirely when Drive is off. A stale link row is
  untidy; refusing a detach the user asked for because Drive is unreachable
  would not be.

Detaching never deletes the document. For a linked file that is the whole point.
For an uploaded one the bytes stay in Drive as well: no purge job is queued for a
Drive-stored attachment, see [What is not done](#what-is-not-done). So dropping
the cross-reference is the *only* thing that stops Drive's document manager from
going on advertising a note that no longer has the file.

One asymmetry to know about: `link-drive` attaches an existing document **without**
registering a `document_links` row for it, while an upload registers one. The
detach path covers both, so the vacuous case is a lookup that matches nothing.
Registering the link for a file the user linked would be the more complete §29
answer and is not built.

## The local fallback

`LocalObjectStore` exists so an unconfigured deployment still works:

- keys are `YYYY/MM/<32 hex chars>` — allocated, not derived from the filename,
  and re-validated against that exact shape on every read, so a `storage_key`
  that somehow acquired `../` cannot address a file outside the root;
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

That is the **local** store's path, and it is unchanged. A Drive-stored file
takes the same route by default, and the reason is CORS rather than anything
about Drive's API.

The obvious design is a `302` to the presigned GET Drive issues
(`GET /api/documents/{id}/download`, which Drive answers only after its own
authorization ladder — session, environment, tenant context, owner, ACL, scope,
object-key root — with `{url, expires_in, filename, mime_type}`). It authorises
the file twice, here against the note and there against the document, and keeps
megabytes out of this process. **In a browser it does not work.** The endpoint
needs a Bearer token, so the SPA `fetch`es it rather than navigating to it — and
a `fetch` that follows a redirect to another origin is subject to *that* origin's
CORS rules. Drive's private bucket lists exactly one origin
(`drive-react-app/server-php/scripts/cors-prod.json`:
`https://drive.aicountly.com`). Notes is not on it, so the redirect ends in
"Failed to fetch": no status, no error body, nothing to show the user but a file
that will not open. Drive's own README gives the matching advice for the upload
direction — *prefer a server-side S3 PUT from the product API when the product
origin is not on the Drive S3 CORS allowlist* — and a download is the same
problem pointing the other way.

So Notes serves the bytes itself, because the default has to be the one that
works. A deployment whose origin **has** been added to the bucket's allowlist
(`drive-react-app/docs/S3_CORS.md` says how) sets
`NOTES_DRIVE_DIRECT_DOWNLOAD=true` and gets the redirect, and the saving, back.
`LocalObjectStore::signedUrl()` still answers `null` either way, because its
directory is denied to Apache and a link it signed would only 403.

`DriveDocumentService::fetch()` does pull bytes into the process — signed URL,
then a GET to the object store — for the code that genuinely needs content in
memory. It is not how a download is served.

## Envelopes

Drive answers `{success: true, data, errors: []}`
(`SesAuthController::jsonSuccess`) — **not** the `{status: 1, data}` envelope
Pulse and the portal use, and not to be confused with the `{success: true, data}`
shape Notes returns to its own clients. `AicountlyClient` recognises an envelope
by its own key and never unwraps a refusal as a payload; treating a permission
denial as data is how a 403 reaches the UI as a row of nulls instead of an error.

## Processing

An upload does not wait for processing — a thumbnail must not stand between
someone and their note. The request stores the bytes, records the attachment —
`processing_status: queued`, or `skipped` when there is nothing to run — and
returns.

`bin/worker.php` then runs the job. Text extraction and thumbnails happen
locally; OCR and transcription post to a configured service and are gated on
their own flags. **With a flag off, the job is marked `skipped`, not `failed`,
and is not retried** — there is nothing to retry until the deployment gains that
capability.

**A Drive-stored attachment queues none of them.** Every one of those jobs has to
read the object back, that means a call to Drive, and Drive is reached on the
caller's own `ses_key` — which a cron worker does not have and must not be given.
Queuing them anyway would produce a job that fails, retries and fails again for
every upload, so `enqueueProcessing()` returns 0 and the attachment is honestly
`skipped`. A Drive deployment therefore derives nothing from its attachments: no
thumbnails, no OCR, no PDF text, no transcripts, nothing appended to
`notes.derived_text`. That is a real limitation, not a temporary shape — see
[What is not done](#what-is-not-done).

Whatever text a job produces is appended to `notes.derived_text`, which feeds the
search vector at weight C. It is kept apart from `extracted_text` so re-saving a
note never discards OCR output and re-processing an attachment never overwrites
what the user typed.

## Failure

Drive is a service, not a mirror, so a failure is treated as retryable: the API
answers `502 UPSTREAM_UNAVAILABLE`, the attachment keeps its row, and the job is
retried with backoff. **Response bodies are never logged** — an object store
quotes the key back in its errors, and a key names the product, the tenant and
the file.

## What is not done

Stated plainly, because an integration that is *ready for* something is not the
same as having it.

- **Audio and video cannot be stored in Drive at all.** Drive's session-create
  allowlist has no `audio/` or `video/` prefix, so every type behind the
  `voice-notes` and `meeting-recordings` modules — and `text/markdown` and
  `application/rtf` besides — is refused at step 1 (see
  [Notes' onboarding record](#notes-onboarding-record)). A deployment that turns
  Drive on loses the ability to attach a recording, which is a bigger change than
  "where new files go" and the reason to read that section before flipping the
  flag. Closing it is a one-line change to `ALLOWED_MIME_PREFIXES` in
  `drive-react-app`, which §29 explicitly anticipates — not something Notes can
  do, and not something to work around here by lying about a file's type.
- **Drive still lists Notes as Future.** §10 of the architecture document records
  `Notes | notes.aicountly.com | notes | Generic | Future`, and Drive's
  cross-repository `STORAGE_REFERENCE.md` has **no Notes row at all** — neither
  in its per-product breakdown (§4) nor in its cross-repo summary (§8). Drive's
  own vocabulary defines what would change that: *Live* means a real client in
  this repository, reached on the product's normal path, verified by reading this
  repo; *Implemented, opt-in* means the client exists but a config switch selects
  it. The client exists and is tested, and it is reached only when
  `NOTES_DRIVE_ENABLED=true` — so *Implemented, opt-in* is the accurate status
  today, and *Live* follows the first deployment that turns the flag on.
  **Recording either is a change in `drive-react-app`, not in this repository**:
  §10's status column, and a `STORAGE_REFERENCE.md` §4/§8 entry. Notes cannot
  make it and should not try; Drive's documents say their statuses go stale and
  must be re-verified against the product's own repo.
- **A file attached with `link-drive` gets no `document_links` row.** An upload
  registers one at finalize and drops it on detach (see
  [Cross-references](#cross-references)), but a document the user already had in
  Drive is attached without telling Drive that the note now references it. The
  detach path already handles the row if one ever exists, so closing this is one
  call in `AttachmentService::linkDrive()` — it is simply not built.
- **Nothing is derived from a Drive-stored attachment**, for the reason in
  *Processing*. Closing it needs a decision that has not been made: either Drive
  gains a way for a product's backend to read an object it owns without an
  end-user session, or Notes derives what it needs from the bytes it already
  holds in memory during the upload request.
- **A Drive object is trashed on detach, not purged — and only when a person is
  there to do it.** Detaching soft-deletes the attachment row and, for a
  **local** file, queues `attachment.object_purge` to remove the bytes. A
  Drive-stored one cannot go through that job: it runs in the worker through
  `storeFor($attachment)`, which has no session behind it, and `DriveObjectStore`
  rightly refuses rather than inventing a caller — queued anyway it could only
  fail, retry with backoff and settle as a permanent failure, on every deletion,
  for ever. So the work happens **in the request**, where the caller's ses_key
  exists: the `document_links` row is dropped, and a document *Notes uploaded*
  is sent to Drive's trash with `POST /api/documents/{id}/trash`
  (`AttachmentService::driveDisposition()` decides which of the two it is).
  Trash and not `DELETE`: Drive's own restore puts it back, and its retention
  policy still gets a veto. A file the user **linked** from their own Drive is
  never trashed — detaching it from a note is not a request to throw it away.
  What remains open is the path with no request behind it: a note purged from
  Trash by the retention job takes its attachment rows with it and leaves the
  Drive documents where they are. Same root cause as the point above, and the
  same decision closes both.
- **Drive's delete cannot say *why* it refused.** `DELETE /api/documents/{id}`
  answers `DELETE_FAILED` with HTTP 403 for every failure alike — a document
  that is not visible to the caller, one on legal hold, one still inside its
  retention window, and one that is simply already gone. `AicountlyClient` maps
  403 and 404 both to `NOT_FOUND` on purpose (that product decides, and "not
  yours" and "not there" are one answer), so `DriveDocumentService::delete()`
  cannot tell a refusal from an absence: it answers `false` — "there was nothing
  to remove" — for all four. The failure mode is therefore the opposite of a
  stuck retry: a delete Drive **refused** is indistinguishable from one there was
  nothing to do. The only path that reaches it today is the rollback in
  `AttachmentService::upload()`, when the bytes landed but the row would not
  insert, and there the `false` is swallowed and the orphan document stays.
  Nothing is lost — Drive still holds it — but Notes stops tracking it.
  Distinguishing the cases needs a discriminated error from Drive, which is a
  change in `drive-react-app`.
- **Versions.** Drive supports document versions; every Notes upload creates a
  new document at version 1. Replacing an attachment in place — a second version
  of the same Drive document — is not built.
- **Quotas.** Drive accounts for storage per tenant. Notes neither reads nor
  displays that, and enforces only its own `NOTES_MAX_ATTACHMENT_SIZE`.
- **Nothing here has run against a live Drive.** The sequence, the field names
  and the response shapes were read out of `drive-react-app` and are covered by
  tests against a fake transport. The first real deployment is still the first
  real test.

## Turning it on

1. Set `NOTES_DRIVE_ENABLED=true` in `api/.env`. That is the only setting *this*
   repository needs — the origin is derived from this deployment's hostname —
   but see steps 4 and 5 for what to check on Drive's side and what to expect.
2. `GET /api/config` should now report `drive: true`, and new uploads will go to
   Drive instead of the local disk. One control appears with it: `AttachmentList`
   renders an **Attach from Drive** button behind `useFeature('drive')`, opening a
   dialog that takes a document id — or a Drive URL, from which `parseDriveFileId`
   lifts the id — and calls `link-drive` through `useAttachments.attachDriveFile`.
   It is not a file *picker*: nothing browses Drive from inside Notes, so the user
   has to get the id from Drive themselves.
3. For **company** notes, the client must send `cmp_id`, `fy_id` and `bo_id` as
   query parameters; personal notes need nothing, because they carry Drive's
   `cmp_id=fy_id=bo_id=0` sentinel. See
   [Company context](#company-context-and-personal-notes).
4. Confirm `CHECKSUM_ON_FINALIZE` is **not** switched off on the Drive being
   pointed at, or every upload will fail at finalize. See
   [What Notes tells Drive about a file](#what-notes-tells-drive-about-a-file).
5. Expect no thumbnails, OCR or transcripts on new attachments; expect the bytes
   of a deleted attachment to stay in Drive; and expect audio and video uploads
   to be **refused outright** by Drive's MIME allowlist, which is a capability
   the deployment loses rather than merely relocates. See
   [What is not done](#what-is-not-done).

Existing local attachments keep working, and keep being served from disk.
