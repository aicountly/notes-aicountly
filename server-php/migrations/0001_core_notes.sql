-- ---------------------------------------------------------------------------
-- 0001 — core note storage: notebooks, notes, revisions, tags.
--
-- Identifiers: our own rows use `uuid`. Anything owned by the AICOUNTLY portal
-- (user and company ids) is varchar(64), because the portal is a separate
-- service and its identifier format is not ours to constrain. That also rules
-- out a foreign key to a table this database does not have.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE notebooks (
    id                  uuid         PRIMARY KEY,
    tenant_id           varchar(64)  NULL,
    owner_user_id       varchar(64)  NOT NULL,
    parent_id           uuid         NULL REFERENCES notebooks (id) ON DELETE CASCADE,
    name                varchar(200) NOT NULL,
    description         text         NULL,
    icon                varchar(60)  NULL,
    color               varchar(30)  NULL,
    position            integer      NOT NULL DEFAULT 0,
    depth               smallint     NOT NULL DEFAULT 0,
    is_archived         boolean      NOT NULL DEFAULT FALSE,
    created_by          varchar(64)  NOT NULL,
    updated_by          varchar(64)  NOT NULL,
    created_at          timestamptz  NOT NULL DEFAULT now(),
    updated_at          timestamptz  NOT NULL DEFAULT now(),
    deleted_at          timestamptz  NULL
);

CREATE INDEX notebooks_owner_idx  ON notebooks (owner_user_id) WHERE deleted_at IS NULL;
CREATE INDEX notebooks_tenant_idx ON notebooks (tenant_id)     WHERE deleted_at IS NULL;
CREATE INDEX notebooks_parent_idx ON notebooks (parent_id)     WHERE deleted_at IS NULL;

-- Two notebooks may not share a name under the same parent for the same owner.
-- Two partial indexes rather than one, because NULL parent_id never equals
-- itself and a plain unique index would let duplicates at the root through.
CREATE UNIQUE INDEX notebooks_unique_child_name_idx
    ON notebooks (owner_user_id, parent_id, lower(name))
    WHERE deleted_at IS NULL AND parent_id IS NOT NULL;
CREATE UNIQUE INDEX notebooks_unique_root_name_idx
    ON notebooks (owner_user_id, lower(name))
    WHERE deleted_at IS NULL AND parent_id IS NULL;


CREATE TABLE notes (
    id                      uuid         PRIMARY KEY,
    tenant_id               varchar(64)  NULL,
    owner_user_id           varchar(64)  NOT NULL,
    notebook_id             uuid         NULL REFERENCES notebooks (id) ON DELETE SET NULL,
    note_type               varchar(30)  NOT NULL DEFAULT 'document',

    title                   varchar(500) NULL,

    -- ProseMirror document. The note IS this structure; `extracted_text` and
    -- `search_vector` are derived from it and are never the source of truth.
    document_json           jsonb        NOT NULL DEFAULT '{"type":"doc","content":[]}'::jsonb,
    document_schema_version integer      NOT NULL DEFAULT 1,

    -- Flattened text of document_json, written by the application on save.
    extracted_text          text         NOT NULL DEFAULT '',
    -- OCR / transcript text contributed by background jobs. Kept apart from
    -- extracted_text so a re-save of the note never discards it, and a
    -- re-processed attachment never overwrites what the user typed.
    derived_text            text         NOT NULL DEFAULT '',

    color                   varchar(30)  NULL,
    is_pinned               boolean      NOT NULL DEFAULT FALSE,
    is_favourite            boolean      NOT NULL DEFAULT FALSE,
    is_archived             boolean      NOT NULL DEFAULT FALSE,
    is_locked               boolean      NOT NULL DEFAULT FALSE,

    -- standard  — server-side storage, eligible for AI and indexing
    -- private   — client-encrypted; the server holds ciphertext it cannot read
    privacy_mode            varchar(20)  NOT NULL DEFAULT 'standard',

    -- Optimistic concurrency. A PATCH carrying a stale version is refused
    -- rather than allowed to overwrite a newer document.
    version                 integer      NOT NULL DEFAULT 1,
    content_hash            varchar(64)  NOT NULL DEFAULT '',

    source                  varchar(40)  NULL,
    template_key            varchar(60)  NULL,
    language                varchar(16)  NULL,
    word_count              integer      NOT NULL DEFAULT 0,
    char_count              integer      NOT NULL DEFAULT 0,

    created_by              varchar(64)  NOT NULL,
    updated_by              varchar(64)  NOT NULL,
    created_at              timestamptz  NOT NULL DEFAULT now(),
    updated_at              timestamptz  NOT NULL DEFAULT now(),
    deleted_at              timestamptz  NULL,

    -- Generated, so it can never drift from the columns it summarises.
    -- Weighted A/B/C: a title hit outranks a body hit, which outranks OCR.
    search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(extracted_text, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(derived_text, '')), 'C')
    ) STORED,

    CONSTRAINT notes_note_type_chk CHECK (note_type IN
        ('document', 'checklist', 'voice', 'meeting', 'drawing', 'canvas', 'scan')),
    CONSTRAINT notes_privacy_mode_chk CHECK (privacy_mode IN ('standard', 'private'))
);

-- The list query: one owner's live, unarchived notes, newest first.
CREATE INDEX notes_owner_updated_idx  ON notes (owner_user_id, updated_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX notes_tenant_updated_idx ON notes (tenant_id, updated_at DESC)     WHERE deleted_at IS NULL;
CREATE INDEX notes_notebook_idx       ON notes (notebook_id, updated_at DESC)   WHERE deleted_at IS NULL;
CREATE INDEX notes_type_idx           ON notes (owner_user_id, note_type)       WHERE deleted_at IS NULL;
CREATE INDEX notes_pinned_idx         ON notes (owner_user_id, updated_at DESC) WHERE deleted_at IS NULL AND is_pinned;
CREATE INDEX notes_archived_idx       ON notes (owner_user_id, updated_at DESC) WHERE deleted_at IS NULL AND is_archived;
-- Trash retention sweeps read this one.
CREATE INDEX notes_deleted_idx        ON notes (deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX notes_search_idx         ON notes USING GIN (search_vector);


CREATE TABLE note_revisions (
    id                  uuid         PRIMARY KEY,
    note_id             uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    revision_number     integer      NOT NULL,
    title               varchar(500) NULL,
    document_json       jsonb        NOT NULL,
    extracted_text      text         NOT NULL DEFAULT '',
    content_hash        varchar(64)  NOT NULL DEFAULT '',
    -- autosave | manual | restore | import | template
    reason              varchar(30)  NOT NULL DEFAULT 'autosave',
    created_by          varchar(64)  NOT NULL,
    created_at          timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_revisions_unique UNIQUE (note_id, revision_number)
);

CREATE INDEX note_revisions_note_idx ON note_revisions (note_id, revision_number DESC);


CREATE TABLE tags (
    id              uuid         PRIMARY KEY,
    tenant_id       varchar(64)  NULL,
    owner_user_id   varchar(64)  NOT NULL,
    -- What the user typed. Preserved exactly.
    name            varchar(80)  NOT NULL,
    -- Case- and space-folded. This is what uniqueness and lookup use, so
    -- "GST" and "gst" resolve to one tag instead of two.
    slug            varchar(80)  NOT NULL,
    color           varchar(30)  NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT tags_unique_slug UNIQUE (owner_user_id, slug)
);

CREATE INDEX tags_tenant_idx ON tags (tenant_id);


CREATE TABLE note_tags (
    note_id     uuid        NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    tag_id      uuid        NOT NULL REFERENCES tags (id)  ON DELETE CASCADE,
    created_at  timestamptz NOT NULL DEFAULT now(),

    PRIMARY KEY (note_id, tag_id)
);

CREATE INDEX note_tags_tag_idx ON note_tags (tag_id);

-- @DOWN

DROP TABLE IF EXISTS note_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS note_revisions;
DROP TABLE IF EXISTS notes;
DROP TABLE IF EXISTS notebooks;
