-- ---------------------------------------------------------------------------
-- 0002 — sharing, comments and the activity trail.
--
-- Access is granted in two places: directly on a note, or on a notebook (which
-- cascades to its notes and to descendant notebooks). NotePermissionService is
-- the only code that reads these tables; controllers never do.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_members (
    id              uuid         PRIMARY KEY,
    note_id         uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    user_id         varchar(64)  NOT NULL,
    role            varchar(20)  NOT NULL,
    invited_by      varchar(64)  NOT NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_members_unique UNIQUE (note_id, user_id),
    CONSTRAINT note_members_role_chk CHECK (role IN ('owner', 'editor', 'commenter', 'viewer'))
);

CREATE INDEX note_members_user_idx ON note_members (user_id);


CREATE TABLE notebook_members (
    id              uuid         PRIMARY KEY,
    notebook_id     uuid         NOT NULL REFERENCES notebooks (id) ON DELETE CASCADE,
    user_id         varchar(64)  NOT NULL,
    role            varchar(20)  NOT NULL,
    invited_by      varchar(64)  NOT NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT notebook_members_unique UNIQUE (notebook_id, user_id),
    CONSTRAINT notebook_members_role_chk CHECK (role IN ('owner', 'editor', 'commenter', 'viewer'))
);

CREATE INDEX notebook_members_user_idx ON notebook_members (user_id);


CREATE TABLE note_comments (
    id              uuid         PRIMARY KEY,
    note_id         uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    -- NULL for a top-level comment; otherwise the comment it replies to.
    parent_id       uuid         NULL REFERENCES note_comments (id) ON DELETE CASCADE,
    -- ProseMirror block id this comment is anchored to. NULL = whole note.
    block_id        varchar(64)  NULL,
    -- The text the anchor covered when the comment was written. Kept so a
    -- comment stays meaningful after the block it pointed at is edited away.
    anchor_text     text         NULL,
    body            text         NOT NULL,
    author_user_id  varchar(64)  NOT NULL,
    mentions        jsonb        NOT NULL DEFAULT '[]'::jsonb,
    resolved_at     timestamptz  NULL,
    resolved_by     varchar(64)  NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),
    deleted_at      timestamptz  NULL
);

CREATE INDEX note_comments_note_idx   ON note_comments (note_id, created_at) WHERE deleted_at IS NULL;
CREATE INDEX note_comments_parent_idx ON note_comments (parent_id)           WHERE deleted_at IS NULL;
CREATE INDEX note_comments_block_idx  ON note_comments (note_id, block_id)   WHERE deleted_at IS NULL;


CREATE TABLE note_activity (
    id              uuid         PRIMARY KEY,
    note_id         uuid         NULL REFERENCES notes (id) ON DELETE CASCADE,
    notebook_id     uuid         NULL REFERENCES notebooks (id) ON DELETE CASCADE,
    actor_user_id   varchar(64)  NOT NULL,
    action          varchar(60)  NOT NULL,
    -- Never note bodies. Ids, role names, counts — things safe to keep and to
    -- show to every collaborator on the note.
    context         jsonb        NOT NULL DEFAULT '{}'::jsonb,
    created_at      timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX note_activity_note_idx     ON note_activity (note_id, created_at DESC);
CREATE INDEX note_activity_notebook_idx ON note_activity (notebook_id, created_at DESC);
CREATE INDEX note_activity_actor_idx    ON note_activity (actor_user_id, created_at DESC);

-- @DOWN

DROP TABLE IF EXISTS note_activity;
DROP TABLE IF EXISTS note_comments;
DROP TABLE IF EXISTS notebook_members;
DROP TABLE IF EXISTS note_members;
