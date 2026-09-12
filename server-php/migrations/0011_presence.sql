-- ---------------------------------------------------------------------------
-- 0011 — presence, for live collaboration.
--
-- One row per (note, user): who currently has this note open, and when they
-- were last heard from. A second tab from the same person refreshes their
-- one row rather than adding a second — "who is here" is a question about
-- people, not about browser tabs.
--
-- No trigger, no NOTIFY: this is read and written entirely by the polling
-- endpoint in PresenceService. See docs/REALTIME.md for why polling and not
-- a persistent connection — the short version is that cPanel has no daemon
-- to hold one open, and Pulse's own `useNotifications.js` documents reaching
-- the identical conclusion for the same shared-hosting constraint.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_presence (
    note_id      uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    user_id      varchar(64)  NOT NULL,
    -- Denormalised so a viewer list never has to join back to a portal user
    -- table this API does not have. Refreshed on every heartbeat, so a
    -- display-name change shows up within one poll interval.
    display_name varchar(200) NOT NULL,
    last_seen_at timestamptz  NOT NULL DEFAULT now(),

    PRIMARY KEY (note_id, user_id)
);

-- "Who is active on this note" filters on last_seen_at with the note already
-- pinned by the primary key's leading column, so the primary key itself
-- serves that query. This index is for the sweep in the other direction —
-- every stale row, regardless of note — which the primary key cannot serve
-- since note_id leads it.
CREATE INDEX note_presence_last_seen_idx ON note_presence (last_seen_at);

-- @DOWN

DROP TABLE IF EXISTS note_presence;
