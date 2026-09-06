# Offline and sync

A notes app that shows nothing on a train is not a notes app. This is what Notes
actually does without a network, and what it deliberately does not.

## What works offline

- Reading every note that has been listed or opened on this device.
- Opening and **editing** a note whose document has been cached.
- Creating a note.
- Ticking checklist items, pinning, favouriting, archiving, trashing.
- Searching the cached list by title and excerpt (client-side).

## What does not

- Full-text search over note bodies (that is a Postgres `tsvector` query).
- Attachments that have not been downloaded, and any upload.
- Anything requiring Pulse, Drive, Calendar, Contacts or Connect.
- Sharing, comments, version history.

These are not hidden when offline; they report that they need a connection.

## Storage

**IndexedDB**, through a small hand-rolled wrapper (`shared/offline/db.ts`).
`localStorage` was the wrong tool: synchronous, so writing a note blocks the
editor's frame; string-only, so every document is stringified twice; and capped
at a few megabytes, which a hundred notes with inline images will exceed.

Three stores:

| Store | Holds |
|---|---|
| `notes` | cached notes, each flagged `dirty` when it has unsent changes |
| `sync-queue` | mutations waiting for a network |
| `meta` | the sync cursor, and which user this cache belongs to |

IndexedDB is unavailable in private-mode Safari and in hardened profiles. Every
call resolves `null` there rather than throwing: the app loses offline support,
never the ability to load.

**A different user signing in on the same device clears the cache** — otherwise
one person's notes would be readable offline by the next person to use that
browser profile.

## The queue

Every offline change becomes one operation:

```ts
{
  operation_id: '…',        // generated on this device
  entity_type: 'note',
  entity_id: '…',
  operation: 'note.update',
  payload: { … },
  client_stamp: '2026-…',
  attempts: 0,
}
```

`operation_id` is what makes replay safe. The server records applied operation
ids in `sync_operations`, so a flaky reconnect that sends the same batch twice
cannot create the note twice.

Two behaviours worth knowing:

- **Updates coalesce.** A second queued update to the same note replaces the
  first. Replaying forty autosaves of one paragraph achieves nothing the last one
  does not, and it turns a reconnect into a stampede.
- **A permanent delete is never queued.** A destruction taken offline and applied
  ten minutes later is not one the user can take back.

## Draining

The engine drains on the `online` event, on tab focus, and on a slow timer. The
timer is the backstop: `online` does not fire when a captive portal stops
intercepting traffic, and a laptop waking from sleep often reports itself as
having been online the whole time.

Operations are sent **sequentially**, oldest first — two operations on the same
note must not race, and the queue is short by construction.

Failures are handled by kind, not uniformly:

| Response | What happens |
|---|---|
| offline again | stop; the queue survives for the next attempt |
| `409 VERSION_CONFLICT` | park it as a conflict for the user (below) |
| any other 4xx | drop it — a deleted note or revoked access will not become a 2xx |
| 5xx / network | retry, up to five attempts |

## Conflicts

**Never resolved automatically.** When the server says a note moved on while this
device was offline, the queued edit is parked and the user is shown both sides:

- **Keep mine** — the local version is saved as a new note, so nothing is lost.
- **Use theirs** — the local edit is discarded, explicitly.

Picking a winner silently is how someone loses an afternoon's writing and never
finds out. The `409` carries the server's current note precisely so this choice
can be offered.

## Saving, when online

Autosave is debounced (~1.2s idle, with a hard flush during continuous typing)
and also flushes on blur and on page hide. The header shows *Saving…* then
*Saved*, and then goes quiet. There is no toast per save — a toast every four
seconds while someone types is an app arguing with its user.

Optimistic concurrency guards the document: each content save carries the version
the client last saw. Metadata toggles are not version-checked, so pinning a note
from a stale list does not fail.

## The service worker

Caches the **shell only** — the HTML, JS, CSS and icons needed to boot with no
network. Note content stays in IndexedDB, where it can be searched, edited and
queued; a Cache Storage entry can do none of that, it survives sign-out, and it
would be readable by the next person to use the browser.

Nothing under `/api/` is ever cached. Navigations are network-first (so a deploy
is picked up immediately) with the cached shell as the offline answer; hashed
build assets are cache-first.

A new build does not reload the page underneath someone mid-sentence — the update
is announced and applied when they say so.

## Not implemented

**CRDT / real-time collaborative editing.** The document is ProseMirror JSON and
the sync boundaries are shaped so Yjs can be added without changing storage, but
there is no realtime server and no custom operational transform was invented in
its place. Two people editing the same note at the same time will produce a
version conflict, handled as above — honestly, and without losing either side.
