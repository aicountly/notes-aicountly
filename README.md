# Aicountly Notes

**Capture anything. Find everything. Act on what matters.**

The knowledge layer of the AICOUNTLY suite: a place to capture text, checklists,
voice, documents and meetings, organise them into notebooks, and turn them into
something searchable and actionable.

| Environment | App | API |
| --- | --- | --- |
| Production | https://notes.aicountly.com | https://notes.aicountly.com/api |
| Sandbox | https://notes.gh.aicountly.com | https://notes.gh.aicountly.com/api |

## What it does

Create a note in about two seconds, from a composer that is one line until you
need more. Write in a real editor — headings, checklists, tables, callouts, code,
images, attachments — with `/` for blocks and for linking another note, and `@`
to mention someone the note is shared with. Tags are added from the note's info
panel. Nothing is ever saved by hand.

File notes into nested notebooks, tag them, pin them, colour them, or leave them
where they land and find them again by searching. Smart folders are saved
queries, so a note can be in as many as you like and in none of them tomorrow.
Share a note or a whole notebook as editor, commenter or viewer. Version history
is always there. So is Trash.

It works on a train: notes are cached on the device, edits are queued, and when
the connection returns they sync — and if the note changed in the meantime, you
are asked, not overruled.

Where the deployment has it enabled, Pulse answers questions across your notes
**with citations** back to the note the answer came from.

For the design decisions behind all of that, start with
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Layout

```
web/          React + Vite SPA. Builds to web/dist, deployed to the document root.
server-php/   The API. Plain PHP 8.4, no dependencies. Deployed to api/ inside it.
docs/         architecture, database, security, deployment, auth, integrations
```

The API is **plain PHP with no Composer dependencies**, because it is deployed by
`rsync` to cPanel with no build step and nowhere to run `composer install`.
Structure comes from a small PSR-4 autoloader, a router and a service layer
rather than from a framework. See
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#why-this-stack).

The database is **PostgreSQL 14+**, and specifically so: the note document is
`jsonb`, search is a generated `tsvector` with a GIN index, and semantic search
expects pgvector. See [docs/DATABASE.md](docs/DATABASE.md).

## Getting started

Requires Node.js 22+, PHP 8.4+ with `pdo_pgsql`, and PostgreSQL 14+.

```bash
# 1. The API
cd server-php
cp .env.example .env          # set APP_ENV=local and the DB_* values
php bin/migrate.php up        # create the schema
php bin/migrate.php seed      # system templates (no demo data, ever)
php -S localhost:8000

# 2. The app
cd ../web
npm install
cp ../.env.example .env       # into web/, which is where Vite reads it
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the **sandbox**
portal. Add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in
`server-php/.env` — localhost is the one case where the app and the API are not
same-origin.

| Command | What it does |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run test` | Component and hook tests (vitest) |
| `npm run e2e` | Browser tests (Playwright, against a stubbed API) |
| `npm run e2e -- --project=desktop` | Just the desktop viewport |
| `php tests/run.php` | API tests, against a real PostgreSQL database |
| `php bin/migrate.php status` | Which migrations are applied |
| `php bin/worker.php --once` | Run queued background jobs once (cron-friendly) |

If the machine already has a Chromium that Playwright did not install — most CI
images and sandboxes do — point at it instead of downloading another copy:

```bash
PLAYWRIGHT_CHROMIUM_PATH=/path/to/chrome npm run e2e
```

### Background jobs

Attachment processing, OCR, transcription and trash retention run outside the
request. cPanel has no queue daemon, so the worker is a CLI command a cron job
calls:

```
*/5 * * * * cd /home/<user>/public_html/api && php bin/worker.php --once >/dev/null 2>&1
```

## Configuration

Two `.env` files that work in opposite ways, and the difference matters:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` (repo root; copy to `web/.env`) | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

Vite inlines every `VITE_*` value when the app is compiled, so **treat every one
of them as public** and never put a secret in one. Changing a frontend value
means rebuilding. `server-php` is the opposite: it reads its `.env` on every
request, so that file lives on the server and only on the server.

### Feature flags

Every capability that depends on something outside this repository — Pulse,
Drive, Calendar, Contacts, Connect, OCR, transcription, semantic search,
canvas, end-to-end encrypted notes — is behind a flag and **defaults to
off**. A flag also stays off when its dependency is unconfigured, so switching on
`NOTES_AI_ENABLED` without a `PULSE_API_URL` cannot produce a UI full of buttons
that fail when pressed.

An endpoint behind an off flag answers `503 FEATURE_DISABLED`; the frontend reads
the same flags from `GET /api/config` and hides or disables the control. There
are no "Coming soon" placeholders. An unconfigured deployment looks like a
smaller product, not a broken one.

See `server-php/.env.example` for the full list, and
[IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md) for what is built,
what is flagged off, and what is not built at all.

## Attachments

Attachment bytes never go in Postgres, and `note_attachments` is a join table
rather than a second object store.

Where the bytes go is one decision in one place: **AICOUNTLY Drive** when
`NOTES_DRIVE_ENABLED=true`, the local disk under `NOTES_STORAGE_PATH` otherwise.

Drive is the suite's storage platform — a metadata service in front of
S3-compatible buckets — not a put/get object store. It issues a presigned S3 URL,
the bytes go **straight to the object store**, and Drive is then told to scan the
object and promote it out of quarantine; downloads come back as a short-lived
presigned URL that Notes redirects to. Notes runs that sequence server-side on
the caller's own session, so Drive enforces its own permissions and the browser
never talks to Drive.

Reading an existing attachment follows the row's `storage_provider`, not the
current flag, so turning Drive on changes where new files go and nothing else.
One caveat before flipping it: Drive's MIME allowlist has no `audio/` or
`video/`, so with Drive on, voice notes and meeting recordings are refused rather
than stored.

See [docs/DRIVE_INTEGRATION.md](docs/DRIVE_INTEGRATION.md) — including what is
not built.

## Signing in

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY SaaS:
the app redirects to the portal, the portal returns an `auth_token`, and the app
exchanges it for a short-lived session key. A user already signed in to another
AICOUNTLY product lands straight in the app. Nothing here mints, signs or stores
a credential.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Deployment

Deployment is **manual only** — nothing deploys on push or merge. **Actions** →
pick a workflow → **Run workflow**.

Web and API deploy together in one run per environment. Both rsync steps use
`--delete`, and the excludes are what make that safe: the web step must not
delete `api/`, and the API step must not delete its `.env` or `storage/`. After a
release that adds a migration, run it once over SSH.

Full detail, including the required secrets and the first-deploy checklist, is in
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Security

Notes holds private thinking, so [docs/SECURITY.md](docs/SECURITY.md) is worth
reading before changing anything in `Domain/Collaboration` or `NoteDocument`.
The short version:

- Authorisation is **one SQL fragment** requiring both a grant and a tenant
  match, composed by every read path — so a list endpoint cannot forget it.
- A note you cannot see is **404, not 403**.
- The note document is client-supplied JSON rendered into the DOM, so
  `NoteDocument::sanitize()` is a strict allowlist of nodes, marks, attributes
  and URL schemes.
- `dangerouslySetInnerHTML` is not used anywhere in the frontend.
- Note content never reaches a log line.
- A presigned object-storage URL is **not** an AICOUNTLY origin, so a session key
  is never sent with one — the signature is already in the URL.
- AI retrieval is permission-filtered **before** ranking, never in the UI, and
  answers from your notes carry citations.
