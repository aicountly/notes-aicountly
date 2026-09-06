-- ---------------------------------------------------------------------------
-- 0008 — pgvector, when the server has it.
--
-- OPTIONAL. `CREATE EXTENSION vector` needs the extension installed on the
-- host and superuser rights, neither of which is guaranteed on shared cPanel.
-- The migration runner treats this file as optional: if it fails, the schema
-- stays valid, note_embeddings keeps its embedding_json fallback, and
-- NOTES_SEMANTIC_SEARCH_ENABLED simply stays off.
--
-- @OPTIONAL
-- ---------------------------------------------------------------------------

-- @UP

CREATE EXTENSION IF NOT EXISTS vector;

ALTER TABLE note_embeddings ADD COLUMN IF NOT EXISTS embedding vector(1536);

-- Cosine distance, which is what an OpenAI-style embedding wants. Lists=100
-- suits the tens of thousands of chunks one tenant produces; revisit when a
-- deployment passes ~1M rows.
CREATE INDEX IF NOT EXISTS note_embeddings_vector_idx
    ON note_embeddings USING ivfflat (embedding vector_cosine_ops)
    WITH (lists = 100);

-- @DOWN

DROP INDEX IF EXISTS note_embeddings_vector_idx;
ALTER TABLE note_embeddings DROP COLUMN IF EXISTS embedding;
