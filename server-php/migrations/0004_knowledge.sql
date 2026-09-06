-- ---------------------------------------------------------------------------
-- 0004 — the knowledge layer: links between notes, links out to the rest of
-- AICOUNTLY, and the embedding store semantic search reads.
--
-- Links are rows, not text parsed at render time. That is what keeps a
-- [[wiki link]] valid after the target note is renamed, and it is what a
-- knowledge graph would later be built from without reprocessing history.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_links (
    id              uuid         PRIMARY KEY,
    source_note_id  uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    target_note_id  uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    -- Block the link sits in, so a backlink can scroll to it.
    source_block_id varchar(64)  NULL,
    -- Link text at the time of writing. Display only — resolution is by id.
    label           varchar(500) NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_links_unique UNIQUE (source_note_id, target_note_id, source_block_id),
    CONSTRAINT note_links_no_self CHECK (source_note_id <> target_note_id)
);

-- Backlinks ("what points at this note?") read this index.
CREATE INDEX note_links_target_idx ON note_links (target_note_id);
CREATE INDEX note_links_source_idx ON note_links (source_note_id);


CREATE TABLE note_entity_links (
    id              uuid         PRIMARY KEY,
    note_id         uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    -- contact | company | employee | calendar_event | drive_file |
    -- connect_meeting | project | invoice | voucher | …
    entity_type     varchar(40)  NOT NULL,
    -- Opaque identifier owned by that product. Deliberately NOT a foreign key:
    -- those products are separate services with separate databases.
    entity_id       varchar(128) NOT NULL,
    -- Cached display label so a list renders without calling four services.
    -- Never authoritative; refreshed when the owning product is reachable.
    label           varchar(400) NULL,
    metadata        jsonb        NOT NULL DEFAULT '{}'::jsonb,
    created_by      varchar(64)  NOT NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_entity_links_unique UNIQUE (note_id, entity_type, entity_id)
);

CREATE INDEX note_entity_links_entity_idx ON note_entity_links (entity_type, entity_id);
CREATE INDEX note_entity_links_note_idx   ON note_entity_links (note_id);


-- Semantic search. The vector column is added by 0009 only when pgvector is
-- installed; without the extension the rest of this table still works and the
-- feature stays flagged off.
CREATE TABLE note_embeddings (
    id              uuid         PRIMARY KEY,
    note_id         uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    tenant_id       varchar(64)  NULL,
    owner_user_id   varchar(64)  NOT NULL,
    chunk_index     integer      NOT NULL DEFAULT 0,
    -- note | attachment | transcript | comment
    source_type     varchar(30)  NOT NULL DEFAULT 'note',
    source_id       uuid         NULL,
    chunk_text      text         NOT NULL,
    -- Re-embedding is skipped when this has not changed, which is what keeps
    -- an autosave every few seconds from becoming an embedding call.
    content_hash    varchar(64)  NOT NULL,
    model           varchar(120) NOT NULL,
    dimensions      integer      NOT NULL DEFAULT 0,
    -- Portable fallback storage, used when pgvector is unavailable.
    embedding_json  jsonb        NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_embeddings_unique UNIQUE (note_id, source_type, source_id, chunk_index, model)
);

CREATE INDEX note_embeddings_note_idx  ON note_embeddings (note_id);
CREATE INDEX note_embeddings_owner_idx ON note_embeddings (owner_user_id);
CREATE INDEX note_embeddings_hash_idx  ON note_embeddings (content_hash);

-- @DOWN

DROP TABLE IF EXISTS note_embeddings;
DROP TABLE IF EXISTS note_entity_links;
DROP TABLE IF EXISTS note_links;
