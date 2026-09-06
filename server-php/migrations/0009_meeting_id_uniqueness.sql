-- ---------------------------------------------------------------------------
-- 0009 — one note per external meeting id.
--
-- `note_meetings.connect_meeting_id` and `calendar_event_id` are what an
-- inbound recording and a calendar sync are routed by: whichever note holds
-- the id receives the transcript of that call. Two notes holding one id makes
-- the routing a race, and the note that wins is the one edited most recently
-- — something anyone can arrange for themselves.
--
-- MeetingService refuses a second claim on every path that writes these
-- columns, which is where the useful error message lives. This index is the
-- floor underneath it: a guard in application code is one forgotten INSERT
-- away from not being a guarantee, and the thing being guarded is somebody
-- else's private conversation.
-- ---------------------------------------------------------------------------

-- @UP

-- Any duplicates predating the guard keep the most recently updated holder and
-- release the rest, which is the same note the old first-past-the-post routing
-- would have chosen. Nothing is deleted: the meeting rows and their notes stay
-- exactly as they are, they simply stop claiming an id that is not theirs.
UPDATE note_meetings m
   SET connect_meeting_id = NULL
 WHERE connect_meeting_id IS NOT NULL
   AND EXISTS (
       SELECT 1 FROM note_meetings other
        WHERE other.connect_meeting_id = m.connect_meeting_id
          AND (other.updated_at, other.note_id) > (m.updated_at, m.note_id)
   );

UPDATE note_meetings m
   SET calendar_event_id = NULL
 WHERE calendar_event_id IS NOT NULL
   AND EXISTS (
       SELECT 1 FROM note_meetings other
        WHERE other.calendar_event_id = m.calendar_event_id
          AND (other.updated_at, other.note_id) > (m.updated_at, m.note_id)
   );

-- Partial, because NULL means "this note is not a meeting record" and any
-- number of notes may say that. A unique index over NULLs would be no
-- constraint at all in Postgres, but the partial form also keeps the index
-- small: most notes have no meeting row, and most meeting rows have at most
-- one of these two ids.
DROP INDEX IF EXISTS note_meetings_calendar_idx;
DROP INDEX IF EXISTS note_meetings_connect_idx;

CREATE UNIQUE INDEX note_meetings_calendar_idx
    ON note_meetings (calendar_event_id) WHERE calendar_event_id IS NOT NULL;

CREATE UNIQUE INDEX note_meetings_connect_idx
    ON note_meetings (connect_meeting_id) WHERE connect_meeting_id IS NOT NULL;

-- @DOWN

DROP INDEX IF EXISTS note_meetings_calendar_idx;
DROP INDEX IF EXISTS note_meetings_connect_idx;

CREATE INDEX note_meetings_calendar_idx
    ON note_meetings (calendar_event_id) WHERE calendar_event_id IS NOT NULL;

CREATE INDEX note_meetings_connect_idx
    ON note_meetings (connect_meeting_id) WHERE connect_meeting_id IS NOT NULL;
