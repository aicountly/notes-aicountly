# Live collaboration

What "realtime" means in this codebase, why it means that and not something
bigger, and exactly where the line sits between what is built and what is not.

## What it is

Two things, delivered together because they are asked for together:

- **Presence.** Who else currently has this note open, shown as a small stack
  of initials in the editor header.
- **A prompt live-update signal.** When someone else saves a note you have
  open, this device finds out within one poll interval — a few seconds — not
  only when your own next save happens to bounce off a version conflict.

That is the whole feature. It is real, tested, and needs nothing outside this
repository to work — unlike every other integration flag in `Features.php`,
`realtime` has no `REQUIRES_ENV` entry.

## What it is not

**Character-level co-editing.** There are no live cursors, no per-keystroke
merge, no CRDT. Two people typing in the same paragraph at the same moment
still resolve exactly the way they did before this existed: the one who saves
second gets the version-conflict banner, told plainly and never resolved by
guessing. `docs/ARCHITECTURE.md`, `docs/NOTES_EDITOR.md` and
`docs/OFFLINE_SYNC.md` each still say, correctly, that Yjs is not implemented
and the document format is merely shaped so that it could be one day. Presence
does not change that. It changes how *often* two people even reach the
conflict banner, by making the common case — nobody else was editing when the
save landed — invisible and immediate instead of something you find out about
by trying to save your own copy over it.

## Why polling, and not a pushed connection

cPanel has no daemon. `docs/DEPLOYMENT.md`'s background-jobs section says the
same thing about the job queue: "cPanel has no queue daemon, so the worker is
a CLI command run by cron." A WebSocket or a server-sent-events stream needs
exactly the thing that is missing — something to hold a connection open
between requests — and a PHP-FPM worker held open per streaming connection is
a worker that is not available to answer the next ordinary API call.

This is not a guess about the constraint. The sibling product that already
looked at this exact problem reached the same conclusion for the same reason.
Pulse's `web/src/hooks/useNotifications.js`:

> Polling pauses while the tab is hidden and backs off after repeated
> failures, so a missing or unhealthy endpoint degrades to a quiet bell rather
> than a request storm. SSE/WebSockets are deliberately avoided: shared cPanel
> hosting already spends a PHP worker per streaming chat turn.

`usePresence` follows the identical shape — pause on `document.hidden`, back
off on consecutive failures, degrade to "nothing shown" rather than an
increasingly stale list — at a shorter interval, because presence is a
faster-moving fact than a notification feed. "Arrives as it happens" means
arrives within a poll interval, honestly stated as that rather than implied to
be instant.

## The wire protocol

One table, `note_presence` (migration `0011`): `(note_id, user_id)` as the
primary key, a denormalised `display_name` refreshed on every heartbeat, and
`last_seen_at`. A second browser tab from the same person updates the same
row — presence answers "who is here", a question about people, not about
tabs.

Two endpoints, both requiring at least `VIEW` on the note (the same floor as
opening it at all — someone who cannot read a note must not learn who else
can):

- `POST /notes/{id}/presence` — a heartbeat. Upserts the caller's own row and
  answers with everyone else's row seen in the last 20 seconds, plus the
  note's current `version` and `updated_at`. One round trip answers both "who
  is here" and "has this moved", because the client asks both questions
  together every time.
- `DELETE /notes/{id}/presence` — leave. Deletes exactly the caller's own row,
  and deliberately carries no permission check beyond that: cleaning up your
  own trace must not be gated on still having access to the note, since losing
  access is one of the reasons a person would be leaving.

Rate-limited (`RateLimiter`'s `presence` bucket, 60/minute per user) — not
because a heartbeat is dangerous, but because a client stuck retrying in a
tight loop should hit a wall before it becomes real load.

A tab that closes without calling `DELETE` — a crash, a lost connection — is
not a leak: it ages out of every `sync()` call's 20-second active window
within seconds, and `PresenceService::sweep()`, run from
`Worker::maintenance()` on the same cron tick as trash retention and
rate-limit housekeeping, removes the row itself (past 10 minutes idle) so the
table does not grow with every note anyone has ever opened.

## The client

`usePresence(noteId, enabled)` (`web/src/features/collaboration/hooks/`) is
the poller: an 8-second interval, backing off to 60 seconds on repeated
failure, paused while the tab is hidden, `DELETE`-ing its own row on cleanup
when the note changes or the component unmounts. It hands back the viewer
list and the note's last-known server version — nothing more. It does not
decide what to do about a newer version; it only notices one exists.

`PresenceAvatars` renders the viewer list as initials with the full name as
the accessible name and the tooltip.

`NoteEditorPane` mounts `usePresence` and, when the version it reports is
newer than what is in the query cache, calls `note.refetch()`. That refetch is
always safe to *ask for* — deciding whether it is safe to *apply* is a rule
that already existed in `NoteEditor.tsx` before presence did, stated in that
file's own header comment: content is written into the editor only when the
note id changed, or the incoming version is genuinely newer **and** the editor
is not focused **and** nothing is waiting to be saved. Presence's entire
contribution is making that refetch happen promptly — within a few seconds of
the other person's save — rather than waiting for this device's own next
autosave to discover the same fact as a 409.

When that rule blocks applying an update because the writer is genuinely
mid-edit, they are not left to find out later by surprise: a light,
dismissible, non-blocking notice — "Someone saved changes to this note" —
says so, distinct from and never shown alongside the conflict banner, which
already says the same thing more forcefully for the case that actually
collided.

## Turning it on

```
NOTES_REALTIME_ENABLED=true
```

Nothing else to configure. Because every open note then polls the database
every few seconds, this is a real and constant load a deployment opts into
deliberately, not a free capability switched on by default the way most flags
in this file are not — this one is not gated on an external dependency at
all, only on that cost.

## Tests

- `server-php/tests/Cases/PresenceTest.php` — the access rule (a stranger
  learns nothing), the active window, one row per person regardless of tabs,
  leaving working even after access is revoked, the maintenance sweep, and the
  feature flag making the endpoint invisible rather than merely inert.
- `web/src/features/collaboration/hooks/usePresence.test.ts` — the poller
  itself: silent when disabled, paused while hidden, backing off and then
  hiding viewers once the server stops answering, and cleaning up the note it
  was polling rather than the one that replaces it.
- `web/src/features/collaboration/components/PresenceAvatars.test.tsx` — the
  avatar stack.
- `web/src/features/editor/NoteEditor.test.tsx`, `'a save that lands while
  this note is open'` — the property that matters most: an unsafe-to-apply
  update never touches what is on screen, and a safe one is applied without
  ceremony.
