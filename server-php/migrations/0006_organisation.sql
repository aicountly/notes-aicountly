-- ---------------------------------------------------------------------------
-- 0006 — templates and smart folders.
--
-- A smart folder is a saved query, not a container: it never moves a note, so
-- one note can appear in any number of them and in none of them tomorrow.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_templates (
    id                  uuid          PRIMARY KEY,
    -- system  — seeded, visible to everyone, not editable by users
    -- tenant  — shared inside one company
    -- user    — private to its owner
    scope               varchar(20)   NOT NULL DEFAULT 'user',
    tenant_id           varchar(64)   NULL,
    owner_user_id       varchar(64)   NULL,

    -- Stable identifier for seeded templates, so re-running the seeder updates
    -- them in place instead of creating duplicates.
    template_key        varchar(60)   NULL,
    name                varchar(200)  NOT NULL,
    description         text          NULL,
    icon                varchar(60)   NULL,
    note_type           varchar(30)   NOT NULL DEFAULT 'document',
    title_template      varchar(500)  NULL,
    document_json       jsonb         NOT NULL,
    -- Tag slugs applied to a note created from this template.
    default_tags        jsonb         NOT NULL DEFAULT '[]'::jsonb,
    position            integer       NOT NULL DEFAULT 0,
    is_archived         boolean       NOT NULL DEFAULT FALSE,

    created_by          varchar(64)   NULL,
    created_at          timestamptz   NOT NULL DEFAULT now(),
    updated_at          timestamptz   NOT NULL DEFAULT now(),
    deleted_at          timestamptz   NULL,

    CONSTRAINT note_templates_scope_chk CHECK (scope IN ('system', 'tenant', 'user')),
    -- A system template has no owner; a user template must have one.
    CONSTRAINT note_templates_owner_chk CHECK (
        (scope = 'system' AND owner_user_id IS NULL) OR
        (scope = 'tenant') OR
        (scope = 'user' AND owner_user_id IS NOT NULL)
    )
);

CREATE UNIQUE INDEX note_templates_system_key_idx
    ON note_templates (template_key)
    WHERE scope = 'system' AND deleted_at IS NULL;
CREATE INDEX note_templates_owner_idx  ON note_templates (owner_user_id) WHERE deleted_at IS NULL;
CREATE INDEX note_templates_tenant_idx ON note_templates (tenant_id)     WHERE deleted_at IS NULL;


CREATE TABLE smart_folders (
    id              uuid          PRIMARY KEY,
    tenant_id       varchar(64)   NULL,
    owner_user_id   varchar(64)   NOT NULL,
    name            varchar(200)  NOT NULL,
    icon            varchar(60)   NULL,
    color           varchar(30)   NULL,
    -- Validated rule tree, e.g.
    --   {"match":"all","conditions":[{"field":"tag","operator":"is","value":"gst"}]}
    -- SmartFolderRuleParser is the only thing that reads it, and it rejects
    -- any field or operator it does not know rather than passing text to SQL.
    rules           jsonb         NOT NULL DEFAULT '{"match":"all","conditions":[]}'::jsonb,
    position        integer       NOT NULL DEFAULT 0,
    created_at      timestamptz   NOT NULL DEFAULT now(),
    updated_at      timestamptz   NOT NULL DEFAULT now(),
    deleted_at      timestamptz   NULL
);

CREATE INDEX smart_folders_owner_idx ON smart_folders (owner_user_id, position) WHERE deleted_at IS NULL;


-- Meeting notes keep their structured half here rather than inside the
-- document, so participants and decisions stay queryable.
CREATE TABLE note_meetings (
    note_id             uuid          PRIMARY KEY REFERENCES notes (id) ON DELETE CASCADE,
    starts_at           timestamptz   NULL,
    ends_at             timestamptz   NULL,
    timezone            varchar(64)   NULL,
    location            varchar(300)  NULL,

    -- Stable external ids. The event itself is not copied here.
    calendar_event_id   varchar(128)  NULL,
    connect_meeting_id  varchar(128)  NULL,

    -- [{contact_id, name, email, role}] — contact_id is the Contacts id when
    -- the participant was linked rather than typed.
    participants        jsonb         NOT NULL DEFAULT '[]'::jsonb,
    agenda              jsonb         NOT NULL DEFAULT '[]'::jsonb,
    decisions           jsonb         NOT NULL DEFAULT '[]'::jsonb,
    summary             text          NULL,
    -- Which Pulse model produced `summary` and `decisions`, and when.
    summary_model       varchar(120)  NULL,
    summary_at          timestamptz   NULL,

    created_at          timestamptz   NOT NULL DEFAULT now(),
    updated_at          timestamptz   NOT NULL DEFAULT now()
);

CREATE INDEX note_meetings_calendar_idx ON note_meetings (calendar_event_id)  WHERE calendar_event_id IS NOT NULL;
CREATE INDEX note_meetings_connect_idx  ON note_meetings (connect_meeting_id) WHERE connect_meeting_id IS NOT NULL;
CREATE INDEX note_meetings_starts_idx   ON note_meetings (starts_at);

-- @DOWN

DROP TABLE IF EXISTS note_meetings;
DROP TABLE IF EXISTS smart_folders;
DROP TABLE IF EXISTS note_templates;
