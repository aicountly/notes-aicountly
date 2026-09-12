-- ---------------------------------------------------------------------------
-- 0003 — attachments, background processing, transcripts.
--
-- Bytes are NOT stored here. AICOUNTLY Drive owns the file; this table owns the
-- relationship between a note and that file, plus whatever the processing
-- pipeline has managed to derive from it so far.
-- ---------------------------------------------------------------------------

-- @UP

CREATE TABLE note_attachments (
    id                  uuid          PRIMARY KEY,
    note_id             uuid          NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    -- ProseMirror block this attachment is rendered in, when it is inline.
    block_id            varchar(64)   NULL,

    -- Where the bytes actually live. `drive` is the AICOUNTLY Drive object id;
    -- `local` is the fallback store used when Drive is not configured.
    storage_provider    varchar(20)   NOT NULL DEFAULT 'local',
    storage_key         varchar(500)  NOT NULL,
    drive_file_id       varchar(128)  NULL,

    filename            varchar(400)  NOT NULL,
    mime_type           varchar(160)  NOT NULL,
    byte_size           bigint        NOT NULL DEFAULT 0,
    checksum_sha256     varchar(64)   NULL,
    kind                varchar(20)   NOT NULL DEFAULT 'file',

    -- Set for audio/video only.
    duration_seconds    integer       NULL,
    -- Set for images and generated page previews.
    width               integer       NULL,
    height              integer       NULL,
    thumbnail_key       varchar(500)  NULL,

    -- pending | processing | ready | failed
    upload_status       varchar(20)   NOT NULL DEFAULT 'ready',
    processing_status   varchar(20)   NOT NULL DEFAULT 'pending',
    processing_error    text          NULL,

    -- OCR / PDF text. Rolled up into notes.derived_text so it is searchable.
    extracted_text      text          NULL,

    metadata            jsonb         NOT NULL DEFAULT '{}'::jsonb,
    created_by          varchar(64)   NOT NULL,
    created_at          timestamptz   NOT NULL DEFAULT now(),
    updated_at          timestamptz   NOT NULL DEFAULT now(),
    deleted_at          timestamptz   NULL,

    CONSTRAINT note_attachments_kind_chk CHECK (kind IN
        ('image', 'pdf', 'audio', 'video', 'document', 'spreadsheet',
         'presentation', 'text', 'file')),
    CONSTRAINT note_attachments_upload_chk CHECK (upload_status IN
        ('pending', 'uploading', 'ready', 'failed')),
    CONSTRAINT note_attachments_processing_chk CHECK (processing_status IN
        ('pending', 'queued', 'processing', 'completed', 'failed', 'skipped'))
);

CREATE INDEX note_attachments_note_idx  ON note_attachments (note_id, created_at) WHERE deleted_at IS NULL;
CREATE INDEX note_attachments_drive_idx ON note_attachments (drive_file_id)       WHERE drive_file_id IS NOT NULL;
CREATE INDEX note_attachments_state_idx ON note_attachments (processing_status)   WHERE deleted_at IS NULL;


CREATE TABLE note_processing_jobs (
    id              uuid         PRIMARY KEY,
    job_type        varchar(40)  NOT NULL,
    note_id         uuid         NULL REFERENCES notes (id) ON DELETE CASCADE,
    attachment_id   uuid         NULL REFERENCES note_attachments (id) ON DELETE CASCADE,
    tenant_id       varchar(64)  NULL,
    requested_by    varchar(64)  NOT NULL,

    status          varchar(20)  NOT NULL DEFAULT 'queued',
    priority        smallint     NOT NULL DEFAULT 5,
    attempts        integer      NOT NULL DEFAULT 0,
    max_attempts    integer      NOT NULL DEFAULT 5,
    -- A job whose failure will never resolve itself (unsupported MIME type,
    -- a deleted note) is marked permanent and never retried.
    permanent_failure boolean    NOT NULL DEFAULT FALSE,
    last_error      text         NULL,

    payload         jsonb        NOT NULL DEFAULT '{}'::jsonb,
    result          jsonb        NULL,

    available_at    timestamptz  NOT NULL DEFAULT now(),
    locked_at       timestamptz  NULL,
    locked_by       varchar(64)  NULL,
    started_at      timestamptz  NULL,
    finished_at     timestamptz  NULL,
    created_at      timestamptz  NOT NULL DEFAULT now(),
    updated_at      timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_processing_jobs_status_chk CHECK (status IN
        ('queued', 'processing', 'completed', 'failed', 'cancelled'))
);

-- The worker's claim query: the oldest due job of the highest priority.
CREATE INDEX note_processing_jobs_claim_idx
    ON note_processing_jobs (priority, available_at)
    WHERE status = 'queued';
CREATE INDEX note_processing_jobs_note_idx       ON note_processing_jobs (note_id);
CREATE INDEX note_processing_jobs_attachment_idx ON note_processing_jobs (attachment_id);
-- Operational metrics read this one; it must not scan the whole table.
CREATE INDEX note_processing_jobs_status_idx     ON note_processing_jobs (status, created_at DESC);


CREATE TABLE note_transcripts (
    id                  uuid         PRIMARY KEY,
    note_id             uuid         NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    attachment_id       uuid         NULL REFERENCES note_attachments (id) ON DELETE CASCADE,

    provider            varchar(60)  NOT NULL DEFAULT 'unknown',
    model               varchar(120) NULL,
    language            varchar(16)  NULL,
    status              varchar(20)  NOT NULL DEFAULT 'pending',

    -- Plain text, for reading and for search.
    text                text         NOT NULL DEFAULT '',
    -- [{start, end, speaker, text, confidence}] — timestamps and diarisation
    -- where the provider supplies them.
    segments            jsonb        NOT NULL DEFAULT '[]'::jsonb,
    -- Edits to the transcript never touch the source audio, so a correction
    -- is recorded here rather than by rewriting `text` in place.
    edited_text         text         NULL,
    edited_by           varchar(64)  NULL,
    edited_at           timestamptz  NULL,

    created_at          timestamptz  NOT NULL DEFAULT now(),
    updated_at          timestamptz  NOT NULL DEFAULT now(),

    CONSTRAINT note_transcripts_status_chk CHECK (status IN
        ('pending', 'processing', 'completed', 'failed'))
);

CREATE INDEX note_transcripts_note_idx       ON note_transcripts (note_id);
CREATE INDEX note_transcripts_attachment_idx ON note_transcripts (attachment_id);

-- @DOWN

DROP TABLE IF EXISTS note_transcripts;
DROP TABLE IF EXISTS note_processing_jobs;
DROP TABLE IF EXISTS note_attachments;
