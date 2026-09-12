<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Jobs\Handlers\EmbeddingHandler;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Domain\Search\EmbeddingProvider;
use Aicountly\Api\Domain\Search\EmbeddingSource;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * The writer `note_embeddings` was designed for.
 *
 * The table has existed since migration 0004 — chunk index, content hash,
 * model, a unique constraint shaped for an upsert — and nothing wrote to it,
 * so semantic search could report itself enabled and then answer
 * `keyword_fallback` for ever.
 *
 * The gateway itself is not reachable from a test, and pretending otherwise
 * would be the fake this suite exists to catch. So the embedding call is
 * replaced with one that returns a fixed vector, and what is tested is
 * everything around it: when the job runs at all, what it stores, what it
 * re-uses, and what it refuses to send.
 */
final class EmbeddingsTest extends TestCase
{
    private ApiClient $alice;

    public function name(): string
    {
        return 'Embeddings';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=false');
        putenv('PULSE_API_URL=');
    }

    public function tearDown(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=false');
        putenv('PULSE_API_URL=');
    }

    /** A provider that answers without a network. */
    private static function provider(?array $vector = null): EmbeddingSource
    {
        return new class ($vector ?? array_fill(0, 8, 0.5)) implements EmbeddingSource {
            /** @param array<int, float> $vector */
            public function __construct(private readonly array $vector)
            {
            }

            public function embed(string $text): ?array
            {
                return $text === '' ? null : ['vector' => $this->vector, 'model' => 'test-model'];
            }
        };
    }

    private function note(string $title, string $body): array
    {
        return $this->alice->post('/notes', [
            'title' => $title,
            'document' => Support::doc($body),
        ])['body']['data'];
    }

    private function rows(string $noteId): array
    {
        return Connection::select(
            'SELECT chunk_index, chunk_text, content_hash, model, dimensions
               FROM note_embeddings WHERE note_id = :id ORDER BY chunk_index',
            ['id' => $noteId],
        );
    }

    // -- When it runs at all ------------------------------------------------

    public function testItDoesNothingWhereSemanticSearchIsOff(): void
    {
        $note = $this->note('Quiet', 'nothing to index');

        $result = (new EmbeddingHandler(self::provider()))->handle(['note_id' => $note['id']]);

        // Skipped rather than failed: a deployment that never asked for
        // semantic search must not accumulate red rows for it.
        $this->assertSame(JobHandler::SKIPPED, $result['outcome']);
        $this->assertSame('semantic_search_disabled', $result['reason']);
        $this->assertCount(0, $this->rows($note['id']));
    }

    /**
     * The flag cannot be on without somewhere to send the text.
     *
     * `Features::REQUIRES_ENV` lists `PULSE_API_URL` against semantic search,
     * so switching the flag on in a deployment with no gateway leaves the
     * feature off rather than half on — and this job, which would otherwise
     * queue and retry against nothing, skips with the same reason as a
     * deployment that never asked for it.
     */
    public function testTheFlagIsNotOnWithoutAGateway(): void
    {
        $note = $this->note('Quiet', 'nothing to index');
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=');

        $result = (new EmbeddingHandler(self::provider()))->handle(['note_id' => $note['id']]);

        $this->assertSame(JobHandler::SKIPPED, $result['outcome']);
        $this->assertSame('semantic_search_disabled', $result['reason']);
    }

    // -- What it stores -----------------------------------------------------

    public function testItStoresAChunkPerParagraphWithTheTitleLeading(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $note = $this->note('Board meeting', 'First paragraph about GST.');

        $result = (new EmbeddingHandler(self::provider()))->handle(['note_id' => $note['id']]);

        $this->assertSame(JobHandler::COMPLETED, $result['outcome']);
        $rows = $this->rows($note['id']);
        $this->assertCount(1, $rows);
        $this->assertContainsString('Board meeting', (string) $rows[0]['chunk_text']);
        $this->assertContainsString('GST', (string) $rows[0]['chunk_text']);
        $this->assertSame('test-model', (string) $rows[0]['model']);
        $this->assertSame(8, (int) $rows[0]['dimensions']);
    }

    public function testAnUnchangedChunkKeepsItsVectorInsteadOfBeingReEmbedded(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $note = $this->note('Steady', 'This paragraph does not change.');
        $handler = new EmbeddingHandler(self::provider());

        $this->assertSame(1, $handler->handle(['note_id' => $note['id']])['embedded']);

        // An autosave every few seconds must not become an embedding call
        // every few seconds.
        $second = $handler->handle(['note_id' => $note['id']]);
        $this->assertSame(0, $second['embedded']);
        $this->assertSame(1, $second['unchanged']);
    }

    public function testChunksTheNoteNoLongerHasAreRemoved(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $long = str_repeat('A paragraph about reconciliation. ', 60);
        $note = $this->note('Long', $long . "\n\n" . $long . "\n\n" . $long);
        $handler = new EmbeddingHandler(self::provider());
        $handler->handle(['note_id' => $note['id']]);
        $this->assertTrue(count($this->rows($note['id'])) > 1, 'a long note is several chunks');

        $patched = $this->alice->patch('/notes/' . $note['id'], [
            'document' => Support::doc('Short now.'),
            'version' => $this->alice->get('/notes/' . $note['id'])['body']['data']['version'],
        ]);
        $this->assertSame(200, $patched['status']);
        $handler->handle(['note_id' => $note['id']]);

        // Left behind, the old chunks would go on matching searches for text
        // the note does not contain any more.
        $this->assertCount(1, $this->rows($note['id']));
    }

    /**
     * Re-indexing replaces a chunk rather than adding a second copy of it.
     *
     * The table's own unique constraint includes `source_id`, which is NULL
     * for a note-level chunk — and NULL does not conflict with NULL, so the
     * upsert matched nothing and quietly appended. A search then returned the
     * same note several times, ranked by whichever stale copy scored best.
     */
    public function testReIndexingReplacesAChunkInsteadOfAppending(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $note = $this->note('Steady', 'The first wording.');
        $handler = new EmbeddingHandler(self::provider());
        $handler->handle(['note_id' => $note['id']]);

        $this->alice->patch('/notes/' . $note['id'], [
            'document' => Support::doc('A different wording entirely.'),
            'version' => $this->alice->get('/notes/' . $note['id'])['body']['data']['version'],
        ]);
        $handler->handle(['note_id' => $note['id']]);

        $rows = $this->rows($note['id']);
        $this->assertCount(1, $rows);
        $this->assertContainsString('different wording', (string) $rows[0]['chunk_text']);
    }

    public function testAPrivateNoteIsNeverSentToTheGateway(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $note = $this->note('Merger terms', 'The price is 4.2 crore.');
        (new EmbeddingHandler(self::provider()))->handle(['note_id' => $note['id']]);
        $this->assertCount(1, $this->rows($note['id']));

        Connection::execute(
            "UPDATE notes SET privacy_mode = 'private' WHERE id = :id",
            ['id' => $note['id']],
        );

        $result = (new EmbeddingHandler(self::provider()))->handle(['note_id' => $note['id']]);

        // The mode promises the server cannot read it. Posting its text to an
        // embedding service would be exactly that, at a third party — and what
        // was indexed before the switch is taken back out.
        $this->assertSame(JobHandler::SKIPPED, $result['outcome']);
        $this->assertSame('private_note', $result['reason']);
        $this->assertCount(0, $this->rows($note['id']));
    }

    // -- Queueing -----------------------------------------------------------

    public function testSavingANoteAsksForItToBeReIndexed(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');

        $note = $this->note('Queued', 'content');

        $queued = Connection::selectOne(
            'SELECT count(*) AS c FROM note_processing_jobs
              WHERE note_id = :id AND job_type = :type',
            ['id' => $note['id'], 'type' => JobQueue::EMBEDDING],
        );
        $this->assertTrue((int) $queued['c'] > 0, 'a new note is queued for embedding');
    }

    public function testPinningANoteDoesNotAskForReIndexing(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=https://gateway.example.test');
        $note = $this->note('Queued', 'content');

        Connection::execute('DELETE FROM note_processing_jobs WHERE note_id = :id', ['id' => $note['id']]);
        $this->alice->post('/notes/' . $note['id'] . '/pin');

        $queued = Connection::selectOne(
            'SELECT count(*) AS c FROM note_processing_jobs
              WHERE note_id = :id AND job_type = :type',
            ['id' => $note['id'], 'type' => JobQueue::EMBEDDING],
        );
        // Pinning does not move the text a semantic index is built from.
        $this->assertSame(0, (int) $queued['c']);
    }

    // -- Chunking -----------------------------------------------------------

    public function testChunkingCutsWhereTheWritingWasAndNotMidSentence(): void
    {
        $chunks = EmbeddingProvider::chunk("First paragraph.\n\nSecond paragraph.", 1500);
        $this->assertSame(['First paragraph.' . "\n\n" . 'Second paragraph.'], $chunks);

        // Too small to hold both, so the paragraph boundary is where it cuts.
        $short = EmbeddingProvider::chunk("One.\n\nTwo.", 6);
        $this->assertSame(['One.', 'Two.'], $short);

        $this->assertSame([], EmbeddingProvider::chunk('   '));
    }

    public function testAParagraphTooLongToEmbedIsCutOnAWordBoundary(): void
    {
        $chunks = EmbeddingProvider::chunk(str_repeat('word ', 100), 60);

        $this->assertTrue(count($chunks) > 1);
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_strlen($chunk) <= 60, 'each chunk fits');
            $this->assertFalse(str_starts_with($chunk, ' '), 'and starts on a word');
        }
    }
}
