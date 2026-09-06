-- ---------------------------------------------------------------------------
-- 0005 — checklist items promoted to actions, and reminders.
--
-- A checklist item lives in the ProseMirror document; `note_actions` is the row
-- that gives one a due date, an assignee and a reminder. The document stays the
-- source of truth for the text and the tick, and NoteActionService reconciles
-- rows against blocks on save.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_actions (
    id                  uuid          PRIMARY KEY,
    note_id             uuid          NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    tenant_id           varchar(64)   NULL,
    -- ProseMirror block id of the checklist item this mirrors.
    block_id            varchar(64)   NULL,
    text                text          NOT NULL DEFAULT '',
    status              varchar(20)   NOT NULL DEFAULT 'open',
    priority            varchar(20)   NULL,
    due_at              timestamptz   NULL,
    assigned_user_id    varchar(64)   NULL,
    position            integer       NOT NULL DEFAULT 0,
    -- Set when Pulse extracted this rather than a person typing it.
    origin              varchar(20)   NOT NULL DEFAULT 'manual',
    completed_at        timestamptz   NULL,
    completed_by        varchar(64)   NULL,
    created_by          varchar(64)   NOT NULL,
    created_at          timestamptz   NOT NULL DEFAULT now(),
    updated_at          timestamptz   NOT NULL DEFAULT now(),
    deleted_at          timestamptz   NULL,

    CONSTRAINT note_actions_status_chk   CHECK (status IN ('open', 'done', 'cancelled')),
    CONSTRAINT note_actions_priority_chk CHECK (priority IS NULL OR priority IN ('low', 'normal', 'high', 'urgent')),
    CONSTRAINT note_actions_origin_chk   CHECK (origin IN ('manual', 'checklist', 'pulse'))
);

CREATE INDEX note_actions_note_idx     ON note_actions (note_id, position)             WHERE deleted_at IS NULL;
CREATE INDEX note_actions_open_idx     ON note_actions (assigned_user_id, due_at)      WHERE deleted_at IS NULL AND status = 'open';
CREATE UNIQUE INDEX note_actions_block_idx ON note_actions (note_id, block_id)         WHERE deleted_at IS NULL AND block_id IS NOT NULL;


CREATE TABLE note_reminders (
    id              uuid          PRIMARY KEY,
    note_id         uuid          NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    action_id       uuid          NULL REFERENCES note_actions (id) ON DELETE CASCADE,
    tenant_id       varchar(64)   NULL,
    user_id         varchar(64)   NOT NULL,

    reminder_type   varchar(20)   NOT NULL DEFAULT 'datetime',
    due_at          timestamptz   NOT NULL,
    -- IANA zone the user set it in. Kept so a recurring reminder stays at
    -- "09:00 local" across a daylight-saving change.
    timezone        varchar(64)   NOT NULL DEFAULT 'UTC',
    -- RFC 5545 RRULE, e.g. FREQ=WEEKLY;BYDAY=MO. NULL for a one-off.
    recurrence_rule varchar(300)  NULL,

    status          varchar(20)   NOT NULL DEFAULT 'scheduled',
    snoozed_until   timestamptz   NULL,
    completed_at    timestamptz   NULL,
    notified_at     timestamptz   NULL,

    created_by      varchar(64)   NOT NULL,
    created_at      timestamptz   NOT NULL DEFAULT now(),
    updated_at      timestamptz   NOT NULL DEFAULT now(),
    deleted_at      timestamptz   NULL,

    CONSTRAINT note_reminders_type_chk   CHECK (reminder_type IN ('datetime', 'recurring')),
    CONSTRAINT note_reminders_status_chk CHECK (status IN ('scheduled', 'snoozed', 'completed', 'cancelled'))
);

CREATE INDEX note_reminders_note_idx ON note_reminders (note_id)          WHERE deleted_at IS NULL;
-- The dispatcher's query: what is due for whom.
CREATE INDEX note_reminders_due_idx  ON note_reminders (user_id, due_at)  WHERE deleted_at IS NULL AND status IN ('scheduled', 'snoozed');

-- @DOWN

DROP TABLE IF EXISTS note_reminders;
DROP TABLE IF EXISTS note_actions;
