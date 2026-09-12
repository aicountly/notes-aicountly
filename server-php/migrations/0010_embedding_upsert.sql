-- ---------------------------------------------------------------------------
-- 0010 — make the embedding upsert actually upsert.
--
-- `note_embeddings_unique (note_id, source_type, source_id, chunk_index, model)`
-- cannot do the job it was written for. A note-level chunk has `source_id IS
-- NULL`, and in PostgreSQL two NULLs are not equal for uniqueness — so the
-- constraint never matches those rows, `ON CONFLICT` never fires, and
-- re-indexing a note appends a second copy of every chunk instead of replacing
-- it. Searches then return the same note repeatedly, ranked by whichever stale
-- copy scored best.
--
-- A partial unique index over the non-null columns is the fix, scoped to
-- exactly the rows the general constraint cannot reach. The original stays:
-- it is still correct for chunks that do carry a source_id (an attachment, a
-- transcript, a comment), which is what it was written for.
-- ---------------------------------------------------------------------------

-- @UP

-- Duplicates from before the index existed: keep the most recently updated row
-- for each chunk, which is the one the last successful indexing wrote.
DELETE FROM note_embeddings e
 WHERE e.source_id IS NULL
   AND EXISTS (
       SELECT 1 FROM note_embeddings other
        WHERE other.source_id IS NULL
          AND other.note_id = e.note_id
          AND other.source_type = e.source_type
          AND other.chunk_index = e.chunk_index
          AND other.model = e.model
          AND (other.updated_at, other.id) > (e.updated_at, e.id)
   );

CREATE UNIQUE INDEX note_embeddings_note_chunk_idx
    ON note_embeddings (note_id, source_type, chunk_index, model)
 WHERE source_id IS NULL;

-- @DOWN

DROP INDEX IF EXISTS note_embeddings_note_chunk_idx;
