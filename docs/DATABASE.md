# Database

PostgreSQL 14 or newer. Not a portability choice to be traded away — three
things Notes is built on are Postgres features:

| Feature | Used for |
|---|---|
| `jsonb` | the ProseMirror note document, smart-folder rules, transcript segments |
| generated `tsvector` + GIN | keyword search across titles, bodies, OCR and transcripts |
| `pgvector` (optional) | semantic search |

## Running migrations

Plain SQL files in `server-php/migrations`, applied by a CLI runner. cPanel has
no deploy hook, so this is run once over SSH after a deploy that adds one:

```bash
cd <document root>/api
php bin/migrate.php status     # what is applied
php bin/migrate.php up         # apply what is not
php bin/migrate.php seed       # system templates only — never demo data
php bin/migrate.php down [n]   # roll back the last n
```

Each file has an `-- @UP` and a `-- @DOWN` section, so every migration is
reversible by construction rather than by convention. Postgres runs DDL
transactionally, so a migration that fails leaves no partial schema behind.

### Optional migrations

A file marked `-- @OPTIONAL` may fail without failing the run. `0008_pgvector`
is the one: `CREATE EXTENSION vector` needs the extension installed on the host
and superuser rights, neither of which is guaranteed on shared cPanel. When it
cannot run it is recorded as **skipped** rather than left pending, so later runs
do not keep retrying an extension the host will never have, and semantic search
simply stays flagged off.

```
$ php bin/migrate.php up
applied        0001_core_notes
…
skipped        0008_pgvector  (extension "vector" is not available)
```

That is a healthy result on a host without pgvector. Everything else works.

## Tables

| Table | Holds |
|---|---|
| `notes` | the note: document, derived text, state, tenancy, version |
| `notebooks` | the nested tree notes are filed into |
| `note_revisions` | checkpointed version history |
| `tags`, `note_tags` | normalised tags, many-to-many |
| `note_members`, `notebook_members` | sharing grants |
| `note_comments` | threaded, block-anchored comments |
| `note_activity` | the audit trail — ids and counts, never content |
| `note_attachments` | the note↔file relationship and what was derived from it |
| `note_processing_jobs` | the background queue (OCR, transcription, thumbnails) |
| `note_transcripts` | provider transcript plus the user's corrections |
| `note_links` | `[[wiki links]]` as rows, keyed by id |
| `note_entity_links` | links out to Contacts, Calendar, Drive, Connect… |
| `note_embeddings` | chunked vectors for semantic search |
| `note_actions` | checklist items with a due date, assignee, priority |
| `note_reminders` | per-user reminders, with RRULE recurrence |
| `note_templates` | system, organisation and personal templates |
| `smart_folders` | saved queries — they never move a note |
| `note_meetings` | the structured half of a meeting note |
| `note_presence` | who currently has a note open, for live collaboration — see docs/REALTIME.md |
| `api_sessions` | short-lived cache of portal session lookups (hashed keys) |
| `api_rate_limits` | fixed-window counters |
| `sync_operations` | the idempotency ledger for the offline queue |

## Identifiers

Our own rows use `uuid`, generated in PHP rather than by the database — a note
needs its id *before* the INSERT so the client can create it optimistically,
offline if need be, and have the server store the note the user is already
typing into.

Anything owned by the AICOUNTLY portal (user ids, company ids) is
`varchar(64)`. The portal is a separate service and its identifier format is not
ours to constrain; it also rules out a foreign key to a table this database does
not have. The same reasoning applies to `note_entity_links.entity_id`, which
points at Contacts, Calendar and Drive records living in other products'
databases.

## Search

`notes.search_vector` is a **generated column**, so it can never drift from the
columns it summarises:

```sql
setweight(to_tsvector('english', coalesce(title, '')),          'A') ||
setweight(to_tsvector('english', coalesce(extracted_text, '')), 'B') ||
setweight(to_tsvector('english', coalesce(derived_text, '')),   'C')
```

Three weights, three sources. `extracted_text` is the flattened note, written by
the application on save. `derived_text` is OCR and transcript text contributed by
background jobs — kept in its own column so re-saving a note never discards it
and re-processing an attachment never overwrites what the user typed. A title hit
outranks a body hit, which outranks a hit inside a scanned PDF.

Queries use `websearch_to_tsquery`, which handles quoted phrases and `-excluded`
the way a search box should and never throws on malformed input the way
`to_tsquery` does. `%LIKE%` is not used over note bodies anywhere.

## Indexes

Partial indexes carry the `deleted_at IS NULL` predicate that almost every query
has, so the index stays small as the trash fills:

```sql
CREATE INDEX notes_owner_updated_idx ON notes (owner_user_id, updated_at DESC)
    WHERE deleted_at IS NULL;
```

The list paginates by **keyset** on `(updated_at, id)` rather than `OFFSET`,
which is what keeps page two correct while notes are being edited underneath the
reader.

`note_processing_jobs` has a partial index on `(priority, available_at) WHERE
status = 'queued'` — the worker's claim query — and the claim itself uses
`FOR UPDATE SKIP LOCKED`, so two workers never take the same job.

## cPanel notes

Both the database and the user are prefixed with the account name: a database
created as `notes` becomes `<cpaneluser>_notes`. Use the full prefixed names,
grant the user ALL PRIVILEGES, and leave `DB_HOST=localhost` — on cPanel the
database is on the same machine.

## Testing

`php tests/run.php` truncates every table it knows about, so it refuses to run
against a database whose name does not contain `test`. Set `TEST_DB_NAME` in
`server-php/.env`.
