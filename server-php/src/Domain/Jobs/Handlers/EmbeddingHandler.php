<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Domain\Search\EmbeddingProvider;
use Aicountly\Api\Domain\Search\EmbeddingSource;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Uuid;

/**
 * Keep `note_embeddings` in step with what the note says.
 *
 * The table has been there since migration 0004 — chunk index, content hash,
 * model, a unique constraint shaped for an upsert — and nothing wrote to it.
 * So `POST /search/semantic` could be switched on, report itself enabled in
 * `/api/config`, and then answer `keyword_fallback` for ever, because there
 * was never a row to find. This is the writer that table was designed for.
 *
 * Three things keep it from being expensive:
 *
 *   - **The hash.** A chunk whose text has not changed keeps its vector. An
 *     autosave every few seconds must not become an embedding call every few
 *     seconds, and most edits touch one paragraph out of twenty.
 *   - **Chunking by paragraph.** A chunk is also the snippet a result shows,
 *     so it is cut where the writing was, not every N characters.
 *   - **Doing nothing at all when there is no gateway.** Skipped, not failed:
 *     a deployment without semantic search configured should not accumulate
 *     red rows for work it never asked for.
 *
 * A private note is never embedded. The mode promises the server cannot read
 * it; sending its text to an embedding service would be exactly that, at a
 * third party.
 */
final class EmbeddingHandler implements JobHandler
{
    private const SOURCE_TYPE = 'note';

    public function __construct(
        private readonly EmbeddingSource $embeddings = new EmbeddingProvider(
            EmbeddingProvider::PATIENT_TIMEOUT,
        ),
    ) {
    }

    public function handle(array $job): array
    {
        if (!Features::enabled(Features::SEMANTIC_SEARCH)) {
            return ['outcome' => self::SKIPPED, 'reason' => 'semantic_search_disabled'];
        }
        if (!EmbeddingProvider::configured()) {
            return ['outcome' => self::SKIPPED, 'reason' => 'no_embedding_gateway'];
        }

        $noteId = $job['note_id'] ?? null;
        if (!is_string($noteId)) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'note_missing'];
        }

        $note = Connection::selectOne(
            'SELECT id, tenant_id, owner_user_id, privacy_mode, title, extracted_text, derived_text
               FROM notes WHERE id = :id AND deleted_at IS NULL',
            ['id' => $noteId],
        );
        if ($note === null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'note_missing'];
        }

        if ((string) $note['privacy_mode'] === 'private') {
            // Same rule as the derived-text rollup, for a stronger reason: that
            // one indexes locally, this one would post the text to a gateway.
            $this->forget($noteId);

            return ['outcome' => self::SKIPPED, 'reason' => 'private_note'];
        }

        // The title leads the first chunk because it is often the only place a
        // note says what it is about.
        $body = trim((string) ($note['title'] ?? '') . "\n\n" . (string) ($note['extracted_text'] ?? ''));
        $chunks = EmbeddingProvider::chunk($body);

        if ($chunks === []) {
            $this->forget($noteId);

            return ['outcome' => self::COMPLETED, 'chunks' => 0, 'embedded' => 0];
        }

        $existing = [];
        foreach (Connection::select(
            'SELECT chunk_index, content_hash FROM note_embeddings
              WHERE note_id = :id AND source_type = :source',
            ['id' => $noteId, 'source' => self::SOURCE_TYPE],
        ) as $row) {
            $existing[(int) $row['chunk_index']] = (string) $row['content_hash'];
        }

        $embedded = 0;
        $unchanged = 0;

        foreach ($chunks as $index => $chunk) {
            $hash = hash('sha256', $chunk);
            if (($existing[$index] ?? null) === $hash) {
                $unchanged++;
                continue;
            }

            $vector = $this->embeddings->embed($chunk);
            if ($vector === null) {
                // Thrown, not returned: the contract says a throw is the way to
                // ask for a retry with backoff, and a gateway that is down is
                // the transient case that deserves one. Whatever was already
                // stored stays usable in the meantime — the note is simply not
                // re-indexed yet. The chunk text is not in the message.
                throw new \RuntimeException('The embedding service did not answer.');
            }

            $this->store($note, $index, $chunk, $hash, $vector);
            $embedded++;
        }

        // Chunks the note no longer has. Left behind they would go on matching
        // searches for text the note does not contain any more.
        Connection::execute(
            'DELETE FROM note_embeddings
              WHERE note_id = :id AND source_type = :source AND chunk_index >= :count',
            ['id' => $noteId, 'source' => self::SOURCE_TYPE, 'count' => count($chunks)],
        );

        return [
            'outcome' => self::COMPLETED,
            'chunks' => count($chunks),
            'embedded' => $embedded,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param array<string, mixed> $note
     * @param array{vector: array<int, float>, model: string} $vector
     */
    private function store(array $note, int $index, string $chunk, string $hash, array $vector): void
    {
        $json = (string) json_encode($vector['vector']);

        // The upsert targets the partial index from migration 0010, not the
        // table-level constraint. That one includes `source_id`, which is NULL
        // for a note-level chunk, and NULL never conflicts with NULL — so it
        // matched nothing and every re-index appended a second copy of every
        // chunk instead of replacing it.

        // `embedding_json` is always written; the `vector` column only exists
        // where migration 0008 could install pgvector, so it is set separately
        // and only when the column is there. Search reads whichever it finds.
        Connection::execute(
            'INSERT INTO note_embeddings
                (id, note_id, tenant_id, owner_user_id, chunk_index, source_type, source_id,
                 chunk_text, content_hash, model, dimensions, embedding_json)
             VALUES
                (:id, :note_id, :tenant_id, :owner, :chunk_index, :source_type, NULL,
                 :chunk_text, :content_hash, :model, :dimensions, :embedding_json::jsonb)
             ON CONFLICT (note_id, source_type, chunk_index, model) WHERE source_id IS NULL
             DO UPDATE SET chunk_text = EXCLUDED.chunk_text,
                           content_hash = EXCLUDED.content_hash,
                           dimensions = EXCLUDED.dimensions,
                           embedding_json = EXCLUDED.embedding_json,
                           updated_at = now()',
            [
                'id' => Uuid::v4(),
                'note_id' => (string) $note['id'],
                'tenant_id' => $note['tenant_id'],
                'owner' => (string) $note['owner_user_id'],
                'chunk_index' => $index,
                'source_type' => self::SOURCE_TYPE,
                'chunk_text' => $chunk,
                'content_hash' => $hash,
                'model' => $vector['model'],
                'dimensions' => count($vector['vector']),
                'embedding_json' => $json,
            ],
        );

        if (self::hasVectorColumn()) {
            Connection::execute(
                'UPDATE note_embeddings
                    SET embedding = :embedding::vector
                  WHERE note_id = :note_id AND source_type = :source_type
                    AND source_id IS NULL AND chunk_index = :chunk_index AND model = :model',
                [
                    'embedding' => '[' . implode(',', $vector['vector']) . ']',
                    'note_id' => (string) $note['id'],
                    'source_type' => self::SOURCE_TYPE,
                    'chunk_index' => $index,
                    'model' => $vector['model'],
                ],
            );
        }
    }

    private function forget(string $noteId): void
    {
        Connection::execute(
            'DELETE FROM note_embeddings WHERE note_id = :id AND source_type = :source',
            ['id' => $noteId, 'source' => self::SOURCE_TYPE],
        );
    }

    private static function hasVectorColumn(): bool
    {
        static $present = null;

        return $present ??= Connection::selectOne(
            "SELECT 1 AS present FROM information_schema.columns
              WHERE table_name = 'note_embeddings' AND column_name = 'embedding'",
        ) !== null;
    }
}
