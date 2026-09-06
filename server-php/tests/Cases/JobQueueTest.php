<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Jobs\Handlers\OcrHandler;
use Aicountly\Api\Domain\Jobs\Handlers\RemoteEngine;
use Aicountly\Api\Domain\Jobs\Handlers\TranscriptionHandler;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Domain\Jobs\Worker;
use Aicountly\Api\Env;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * The queue, the worker, and the handlers that can do real work locally.
 *
 * The properties being defended here are the ones whose absence is expensive
 * rather than visible: two workers taking the same job (two OCR calls, two
 * bills), a failing job retrying in a tight loop, a job that can never succeed
 * retrying forever, and a capability that is switched off being reported as a
 * failure instead of as switched off.
 *
 * The thumbnail and text-extraction tests run the real handlers — GD really
 * resizes an image and the PDF parser really reads a compressed content stream
 * — because a passing test against a stubbed handler would prove only that the
 * stub was called.
 */
final class JobQueueTest extends TestCase
{
    private ApiClient $alice;
    private JobQueue $queue;
    private Worker $worker;
    private string $storage;

    public function name(): string
    {
        return 'JobQueue';
    }

    public function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/notes-jobs-' . getmypid();
        self::removeTree($this->storage);

        putenv('NOTES_STORAGE_PATH=' . $this->storage);
        putenv('NOTES_MAX_ATTACHMENT_SIZE=');
        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('DRIVE_API_URL=');
        // Both default to off; pinned here so the suite does not depend on
        // whatever the developer's .env happens to say.
        putenv('NOTES_OCR_ENABLED=false');
        putenv('NOTES_TRANSCRIPTION_ENABLED=false');

        $this->alice = new ApiClient(Support::user('a'));
        $this->queue = new JobQueue();
        $this->worker = new Worker();
    }

    // -- Fixtures -----------------------------------------------------------

    private function note(string $text = 'the quarterly numbers'): array
    {
        return $this->alice->post('/notes', ['title' => 'Board pack', 'document' => Support::doc($text)])['body']['data'];
    }

    private function attach(string $noteId, string $filename, string $bytes): array
    {
        return $this->alice->post('/notes/' . $noteId . '/attachments', [
            'filename' => $filename,
            'content_base64' => base64_encode($bytes),
        ])['body']['data'];
    }

    private static function png(int $width = 100, int $height = 60): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 20, 90, 160));
        $stream = fopen('php://memory', 'r+b');
        imagepng($image, $stream);
        imagedestroy($image);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /**
     * The header of an iPhone photo.
     *
     * Enough of an ISO-BMFF `ftyp` box for finfo to call it `image/heic`, which
     * is all this needs: no PHP build can decode the picture inside one.
     */
    private static function heic(): string
    {
        return pack('N', 24) . 'ftyp' . 'heic' . pack('N', 0) . 'heic' . 'mif1'
            . pack('N', 8) . 'meta' . str_repeat("\x00", 512);
    }

    /** A real, silent WAV — enough for finfo to call it audio. */
    private static function wav(): string
    {
        $samples = str_repeat("\0\0", 8000);
        $fmt = pack('vvVVvv', 1, 1, 8000, 16000, 2, 16);

        return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . $fmt
            . 'data' . pack('V', strlen($samples)) . $samples;
    }

    /**
     * A small but genuine PDF: one page, one content stream, optionally
     * Flate-compressed the way every real writer produces.
     */
    private static function pdf(string $content, bool $compress = true): string
    {
        $stream = $compress ? gzcompress($content, 6) : $content;
        $dictionary = '<</Length ' . strlen($stream) . ($compress ? '/Filter/FlateDecode' : '') . '>>';

        return "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R>>endobj\n"
            . '4 0 obj' . $dictionary . "stream\n" . $stream . "\nendstream\nendobj\n"
            . "trailer<</Root 1 0 R/Size 5>>\n%%EOF\n";
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    /** @return array<string, mixed> */
    private function job(string $jobId): array
    {
        return (array) $this->queue->find($jobId);
    }

    // -- Claiming -----------------------------------------------------------

    public function testAClaimTakesTheHighestPriorityDueJobAndMarksIt(): void
    {
        $note = $this->note();
        $low = $this->queue->enqueue(Support::user('a'), JobQueue::OCR, $note['id'], null);
        $high = $this->queue->enqueue(Support::user('a'), JobQueue::THUMBNAIL, $note['id'], null);

        $claimed = $this->queue->claim('worker-1', 1);
        $this->assertCount(1, $claimed);
        // Thumbnails outrank OCR: one is what the user is looking at.
        $this->assertSame($high, $claimed[0]['id']);
        $this->assertSame('processing', $this->job((string) $high)['status']);
        $this->assertSame(1, (int) $claimed[0]['attempts']);

        // The second claim gets the other job, never the one already taken.
        $this->assertSame($low, $this->queue->claim('worker-1', 1)[0]['id']);
        $this->assertCount(0, $this->queue->claim('worker-1', 1));
    }

    public function testAJobIsNotDueUntilItsDelayHasPassed(): void
    {
        $note = $this->note();
        $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null, [], 3600);

        $this->assertCount(0, $this->queue->claim('worker-1', 5));
    }

    /**
     * Two workers, two connections, one queue.
     *
     * A cron that fires every minute will eventually overlap with a run that
     * took longer than a minute. Without SKIP LOCKED the second worker blocks
     * on the row the first is holding — so this test sets a statement timeout,
     * and a regression shows up as a timeout error rather than a hung suite.
     */
    public function testTwoWorkersNeverClaimTheSameJob(): void
    {
        $note = $this->note();
        $first = $this->queue->enqueue(Support::user('a'), JobQueue::THUMBNAIL, $note['id'], null);
        $second = $this->queue->enqueue(Support::user('a'), JobQueue::OCR, $note['id'], null);

        $other = new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::get('DB_HOST', 'localhost'),
                Env::get('DB_PORT', '5432'),
                Env::get('DB_NAME'),
            ),
            Env::get('DB_USER'),
            Env::get('DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        );

        try {
            // Worker one claims inside a transaction it does not commit, which
            // is exactly the state a worker is in while a job runs.
            $other->beginTransaction();
            $taken = $other->query(
                'WITH due AS (
                    SELECT id FROM note_processing_jobs
                    WHERE status = \'queued\' AND available_at <= now()
                    ORDER BY priority, available_at LIMIT 1 FOR UPDATE SKIP LOCKED
                 )
                 UPDATE note_processing_jobs j SET status = \'processing\', locked_by = \'worker-one\'
                 FROM due WHERE j.id = due.id RETURNING j.id',
            )->fetchAll();

            $this->assertCount(1, $taken);
            $this->assertSame($first, $taken[0]['id']);

            Connection::execute("SET statement_timeout = '4s'");
            $mine = $this->queue->claim('worker-two', 1);
            Connection::execute('SET statement_timeout = 0');

            $this->assertCount(1, $mine, 'the second worker stepped over the locked row');
            $this->assertSame($second, $mine[0]['id']);
            $this->assertNotSame($taken[0]['id'], $mine[0]['id']);
        } finally {
            $other->rollBack();
        }
    }

    // -- Failing well -------------------------------------------------------

    public function testARetryBacksOffAndGivesUpAfterMaxAttempts(): void
    {
        $note = $this->note();
        $jobId = (string) $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null);

        $this->queue->claim('worker-1', 1);
        $this->assertSame('queued', $this->queue->retry($jobId, 'engine_unreachable'));

        $after = $this->job($jobId);
        $this->assertSame('engine_unreachable', $after['last_error']);
        // Backed off rather than retried immediately: an upstream that is
        // struggling must not be hammered by its own error.
        $this->assertCount(0, $this->queue->claim('worker-1', 1));

        // Exhaust the attempts. Each round claims (attempts + 1) then fails.
        for ($round = 0; $round < 10; $round++) {
            Connection::execute(
                'UPDATE note_processing_jobs SET available_at = now() WHERE id = :id',
                ['id' => $jobId],
            );
            if ($this->queue->claim('worker-1', 1) === []) {
                break;
            }
            $this->queue->retry($jobId, 'engine_unreachable');
        }

        $exhausted = $this->job($jobId);
        $this->assertSame('failed', $exhausted['status']);
        $this->assertSame(5, (int) $exhausted['attempts'], 'max_attempts is the ceiling');
        $this->assertNotNull($exhausted['finished_at']);
    }

    public function testAPermanentFailureIsNeverRetried(): void
    {
        $note = $this->note();
        $jobId = (string) $this->queue->enqueue(Support::user('a'), JobQueue::TEXT_EXTRACTION, $note['id'], null);

        $this->queue->claim('worker-1', 1);
        $this->queue->failPermanently($jobId, 'unsupported_mime');

        $failed = $this->job($jobId);
        $this->assertSame('failed', $failed['status']);
        $this->assertTrue((bool) $failed['permanent_failure']);
        $this->assertSame(1, (int) $failed['attempts']);
        $this->assertCount(0, $this->queue->claim('worker-1', 5), 'it is not waiting to be tried again');
    }

    public function testTheSameWorkIsNotQueuedTwice(): void
    {
        $note = $this->note();

        $first = $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null);
        $second = $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null);

        $this->assertNotNull($first);
        // Three attachments finishing together should rebuild the note's search
        // text once, not three times.
        $this->assertNull($second);
        $this->assertSame(1, (int) Connection::selectOne(
            'SELECT count(*) AS c FROM note_processing_jobs WHERE job_type = \'note.derived_text\'',
        )['c']);
    }

    public function testAnUnknownJobTypeFailsPermanentlyRatherThanLooping(): void
    {
        $note = $this->note();
        $jobId = (string) $this->queue->enqueue(Support::user('a'), 'attachment.telepathy', $note['id'], null);

        $tally = $this->worker->run(5, 10);

        $this->assertSame(1, $tally['failed']);
        $this->assertTrue((bool) $this->job($jobId)['permanent_failure']);
    }

    public function testAJobWhoseWorkerVanishedIsGivenBack(): void
    {
        $note = $this->note();
        $jobId = (string) $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null);
        $this->queue->claim('worker-that-died', 1);

        Connection::execute(
            "UPDATE note_processing_jobs SET locked_at = now() - interval '30 minutes' WHERE id = :id",
            ['id' => $jobId],
        );

        $this->assertSame(1, $this->worker->maintenance()['jobs_reaped']);
        $this->assertSame('queued', $this->job($jobId)['status']);
    }

    public function testTheWorkerStopsAtItsBatchLimit(): void
    {
        // One job each, on four notes: the de-duplication guard would fold four
        // identical jobs on one note into a single row.
        foreach (range(1, 4) as $index) {
            $note = $this->note('note ' . $index);
            $this->queue->enqueue(Support::user('a'), 'attachment.telepathy', $note['id'], null);
        }

        $tally = $this->worker->run(2, 30);

        // A cron slot is finite, so a backlog is worked through over several
        // runs rather than in one that overruns.
        $this->assertSame(2, $tally['claimed']);
        $this->assertSame(2, (int) Connection::selectOne(
            'SELECT count(*) AS c FROM note_processing_jobs WHERE status = \'queued\'',
        )['c']);
    }

    // -- Handlers that really work ------------------------------------------

    public function testThumbnailsAreGeneratedLocally(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'chart.png', self::png(100, 60));

        $tally = $this->worker->run(10, 30);
        $this->assertSame(2, $tally['claimed'], 'a thumbnail job and an OCR job');

        $row = Connection::selectOne(
            'SELECT width, height, thumbnail_key, processing_status FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );

        $this->assertSame(100, (int) $row['width']);
        $this->assertSame(60, (int) $row['height']);
        $this->assertNotNull($row['thumbnail_key']);
        $this->assertTrue(is_file($this->storage . '/' . $row['thumbnail_key']), 'the preview was written');

        // A real image, not an empty file: GD produced something PNG can read.
        $size = getimagesize($this->storage . '/' . $row['thumbnail_key']);
        $this->assertSame(100, $size[0]);
        $this->assertSame(60, $size[1]);

        $this->assertSame('completed', (string) $row['processing_status']);
        $this->assertTrue($this->alice->get(
            '/notes/' . $note['id'] . '/attachments/' . $attachment['id'],
        )['body']['data']['has_thumbnail']);
    }

    public function testTextFromAnAttachmentBecomesSearchableWithoutTouchingTheNote(): void
    {
        $note = $this->note('the quarterly numbers');
        $attachment = $this->attach($note['id'], 'ledger.txt', "Zygomorphic ledger entry\nfiled under GST");

        $this->worker->run(10, 30);

        $row = Connection::selectOne(
            'SELECT extracted_text, processing_status FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );
        $this->assertContainsString('Zygomorphic', (string) $row['extracted_text']);
        $this->assertSame('completed', (string) $row['processing_status']);

        $note_row = Connection::selectOne(
            'SELECT extracted_text, derived_text FROM notes WHERE id = :id',
            ['id' => $note['id']],
        );
        // The rollup is a separate column: what the user typed is untouched.
        $this->assertSame('the quarterly numbers', trim((string) $note_row['extracted_text']));
        $this->assertContainsString('Zygomorphic', (string) $note_row['derived_text']);

        // And it is findable, which is the only reason any of this exists.
        $found = $this->alice->get('/notes', ['q' => 'zygomorphic'])['body']['data'];
        $this->assertCount(1, $found);
        $this->assertSame($note['id'], $found[0]['id']);
    }

    public function testPdfTextIsExtractedWithNoExternalService(): void
    {
        $note = $this->note();
        $page = 'BT /F1 12 Tf 72 720 Td [(Quarterly)-320(GST)-320(summary)] TJ 0 -16 Td (Prepared for the board) Tj ET';

        $compressed = $this->attach($note['id'], 'pack.pdf', self::pdf($page, compress: true));
        $this->worker->run(10, 30);

        $text = (string) Connection::selectOne(
            'SELECT extracted_text FROM note_attachments WHERE id = :id',
            ['id' => $compressed['id']],
        )['extracted_text'];

        // The kerning between the strings in a TJ array is what makes these
        // three words rather than one.
        $this->assertContainsString('Quarterly GST summary', $text);
        $this->assertContainsString('Prepared for the board', $text);

        // The same, uncompressed, since not every writer deflates.
        $plain = $this->attach($note['id'], 'plain.pdf', self::pdf($page, compress: false));
        $this->worker->run(10, 30);
        $this->assertContainsString('Quarterly GST summary', (string) Connection::selectOne(
            'SELECT extracted_text FROM note_attachments WHERE id = :id',
            ['id' => $plain['id']],
        )['extracted_text']);

        $this->assertCount(1, $this->alice->get('/notes', ['q' => '"quarterly gst"'])['body']['data']);
    }

    public function testAScannedPdfIsHandedToOcrRatherThanStoringNonsense(): void
    {
        $note = $this->note();
        // A page that draws an image and shows no text — a scan.
        $attachment = $this->attach($note['id'], 'scan.pdf', self::pdf('q 612 0 0 792 0 0 cm /Im0 Do Q'));

        $this->worker->run(10, 30);

        $this->assertSame('', (string) (Connection::selectOne(
            'SELECT coalesce(extracted_text, \'\') AS extracted_text FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        )['extracted_text']));

        // Nothing was read, so the file was passed to the one thing that could.
        $ocr = Connection::selectOne(
            'SELECT status, result FROM note_processing_jobs
             WHERE attachment_id = :id AND job_type = \'attachment.ocr\'',
            ['id' => $attachment['id']],
        );
        $this->assertNotNull($ocr);
    }

    // -- Honest disabled states ---------------------------------------------

    public function testOcrIsSkippedRatherThanFailedWhenNoEngineIsConfigured(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'receipt.png', self::png());

        $tally = $this->worker->run(10, 30);
        $this->assertSame(1, $tally['skipped']);
        $this->assertSame(0, $tally['failed']);

        $ocr = Connection::selectOne(
            'SELECT status, attempts, result->>\'outcome\' AS outcome, result->>\'reason\' AS reason
             FROM note_processing_jobs WHERE attachment_id = :id AND job_type = \'attachment.ocr\'',
            ['id' => $attachment['id']],
        );

        $this->assertSame('completed', (string) $ocr['status']);
        $this->assertSame('skipped', (string) $ocr['outcome']);
        $this->assertSame('feature_disabled', (string) $ocr['reason']);
        $this->assertSame(1, (int) $ocr['attempts'], 'a skipped job is finished, not retried');
        $this->assertCount(0, $this->queue->claim('worker-1', 5));
    }

    public function testTranscriptionIsSkippedAndTheAttachmentSaysSo(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'memo.wav', self::wav());
        $this->assertSame('audio', $attachment['kind']);
        $this->assertSame('queued', $attachment['processing_status']);

        $tally = $this->worker->run(10, 30);

        $this->assertSame(1, $tally['skipped']);
        $this->assertSame(0, $tally['failed']);
        // Its only job was skipped, so the file is not "failed" and not
        // "processing" — nothing here can read it, and the UI can say that.
        $this->assertSame('skipped', (string) Connection::selectOne(
            'SELECT processing_status FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        )['processing_status']);
        $this->assertCount(0, Connection::select('SELECT id FROM note_transcripts'));
    }

    public function testAFlagThatIsOnButUnconfiguredIsStillSkipped(): void
    {
        putenv('NOTES_OCR_ENABLED=true');
        putenv('NOTES_OCR_API_URL=');

        $note = $this->note();
        $attachment = $this->attach($note['id'], 'receipt.png', self::png());
        $this->worker->run(10, 30);

        putenv('NOTES_OCR_ENABLED=false');

        $reason = (string) Connection::selectOne(
            'SELECT result->>\'reason\' AS reason FROM note_processing_jobs
             WHERE attachment_id = :id AND job_type = \'attachment.ocr\'',
            ['id' => $attachment['id']],
        )['reason'];

        // A flag switched on with nowhere to send the file is a misconfigured
        // deployment, not a file that failed.
        $this->assertSame('endpoint_not_configured', $reason);
    }

    // -- Cleanup and housekeeping -------------------------------------------

    public function testThePurgeJobRemovesTheBytesOfADeletedAttachment(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'receipt.png', self::png());
        $this->worker->run(10, 30);

        $keys = Connection::selectOne(
            'SELECT storage_key, thumbnail_key FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );

        $this->alice->delete('/notes/' . $note['id'] . '/attachments/' . $attachment['id']);
        $this->worker->run(10, 30);

        $this->assertFalse(is_file($this->storage . '/' . $keys['storage_key']), 'the file is gone');
        $this->assertFalse(is_file($this->storage . '/' . $keys['thumbnail_key']), 'so is its preview');

        // Nothing points at an object that no longer exists.
        $row = Connection::selectOne(
            'SELECT storage_key, thumbnail_key FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );
        $this->assertSame('', (string) $row['storage_key']);
        $this->assertNull($row['thumbnail_key']);
    }

    public function testMaintenanceEmptiesExpiredTrashAndSweepsTheHousekeepingTables(): void
    {
        $doomed = $this->note('deleted long ago');
        $this->alice->delete('/notes/' . $doomed['id']);
        Connection::execute(
            "UPDATE notes SET deleted_at = now() - interval '400 days' WHERE id = :id",
            ['id' => $doomed['id']],
        );

        Connection::execute(
            "INSERT INTO api_sessions (ses_key_hash, user_id, expires_at)
             VALUES ('dead', 'user-a', now() - interval '1 hour'),
                    ('live', 'user-a', now() + interval '1 hour')",
        );
        Connection::execute(
            "INSERT INTO api_rate_limits (bucket_key, hits, expires_at)
             VALUES ('old', 3, now() - interval '2 hours')",
        );

        $result = $this->worker->maintenance();

        $this->assertSame(1, $result['trash_purged']);
        $this->assertSame(1, $result['sessions_expired']);
        $this->assertSame(1, $result['rate_limits_swept']);
        $this->assertCount(1, Connection::select('SELECT ses_key_hash FROM api_sessions'));
        $this->assertNull(Connection::selectOne('SELECT id FROM notes WHERE id = :id', ['id' => $doomed['id']]));
    }

    public function testATrashedNoteIsKeptUntilItsRetentionWindowPasses(): void
    {
        $kept = $this->note('deleted yesterday');
        $this->alice->delete('/notes/' . $kept['id']);

        $this->assertSame(0, $this->worker->maintenance()['trash_purged']);
        $this->assertNotNull(Connection::selectOne('SELECT id FROM notes WHERE id = :id', ['id' => $kept['id']]));
    }

    public function testJobsForAnotherUsersNoteAreNotSomethingThisUserCanQueue(): void
    {
        $bob = new ApiClient(Support::user('b'));
        $note = $this->note();

        // The queue is only ever fed from a path that has already checked
        // permission, so the guard is on the way in, not on the worker.
        $this->assertSame(404, $bob->post('/notes/' . $note['id'] . '/attachments', [
            'filename' => 'theirs.png',
            'content_base64' => base64_encode(self::png()),
        ])['status']);

        $this->assertSame(0, (int) Connection::selectOne(
            'SELECT count(*) AS c FROM note_processing_jobs WHERE requested_by = \'user-b\'',
        )['c']);
        $this->assertSame(404, $bob->get('/notes/' . $note['id'] . '/attachments')['status']);
    }

    public function testTheQueueReportsItsDepth(): void
    {
        $note = $this->note();
        $this->queue->enqueue(Support::user('a'), JobQueue::DERIVED_TEXT, $note['id'], null);

        $stats = $this->queue->stats();
        $this->assertSame(1, $stats['queued']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testJobPayloadsCarryNoNoteContent(): void
    {
        $note = $this->note('a very private thought about the merger');
        $this->attach($note['id'], 'memo.txt', 'the acquisition price is confidential');
        $this->worker->run(10, 30);

        // Ids, keys and counts only: a job row is operational data that support
        // staff read, and note content has no business being in it.
        foreach (Connection::select('SELECT payload, result, last_error FROM note_processing_jobs') as $row) {
            $blob = strtolower((string) $row['payload'] . (string) $row['result'] . (string) $row['last_error']);
            $this->assertFalse(str_contains($blob, 'confidential'));
            $this->assertFalse(str_contains($blob, 'merger'));
        }
    }

    public function testEveryDetachedFileIsPurgedNotJustTheFirst(): void
    {
        $note = $this->note();
        $first = $this->attach($note['id'], 'one.txt', 'the first receipt, filed');
        $second = $this->attach($note['id'], 'two.txt', 'the second receipt, filed');
        $this->worker->run(20, 30);

        $keys = array_column(Connection::select(
            'SELECT storage_key FROM note_attachments WHERE note_id = :n ORDER BY created_at, id',
            ['n' => $note['id']],
        ), 'storage_key');
        $this->assertCount(2, $keys);

        $this->alice->delete('/notes/' . $note['id'] . '/attachments/' . $first['id']);
        $this->alice->delete('/notes/' . $note['id'] . '/attachments/' . $second['id']);
        $this->worker->run(20, 30);

        // Two detached files are two purges. They carry different keys in their
        // payloads, so folding the second into the first leaves somebody's
        // "deleted" file sitting on the disk forever.
        foreach ($keys as $key) {
            $this->assertFalse(is_file($this->storage . '/' . $key), 'the bytes are gone: ' . $key);
        }
    }
    // -- Engines that answer ------------------------------------------------

    /**
     * An engine that returns what a real one would, without a network.
     *
     * The skip paths are covered above; this is the other half — what the
     * handlers do once something actually comes back.
     */
    private function engine(string $text, int $status = 200, array $segments = []): RemoteEngine
    {
        return new class ($text, $status, $segments) extends RemoteEngine {
            public function __construct(
                private readonly string $text,
                private readonly int $status,
                private readonly array $segments,
            ) {
                parent::__construct('ocr', 'NOTES_OCR_API_URL', 'NOTES_OCR_API_TOKEN');
            }

            public function available(): bool
            {
                return true;
            }

            public function process(string $filename, string $mimeType, string $bytes): array
            {
                return [
                    'status' => $this->status,
                    'text' => $this->text,
                    'language' => 'en',
                    'segments' => $this->segments,
                    'model' => 'test-model',
                ];
            }
        };
    }

    public function testOcrTextLandsOnTheAttachmentAndInTheNotesSearchIndex(): void
    {
        $note = $this->note('the quarterly numbers');
        $attachment = $this->attach($note['id'], 'receipt.png', self::png());

        $worker = new Worker([
            JobQueue::OCR => new OcrHandler(engine: $this->engine('Zygomorphic hardware store receipt')),
        ] + Worker::defaultHandlers());
        $worker->run(10, 30);

        $row = Connection::selectOne(
            'SELECT extracted_text, processing_status FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );
        $this->assertContainsString('Zygomorphic', (string) $row['extracted_text']);
        $this->assertSame('completed', (string) $row['processing_status']);

        // The rollup the OCR handler asks for has to have run, or the words are
        // on the attachment and findable nowhere.
        $this->assertContainsString('Zygomorphic', (string) Connection::selectOne(
            'SELECT derived_text FROM notes WHERE id = :id',
            ['id' => $note['id']],
        )['derived_text']);
        $this->assertCount(1, $this->alice->get('/notes', ['q' => 'zygomorphic'])['body']['data']);
    }

    public function testATranscriptIsRewrittenRatherThanFailingTheSecondTime(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'memo.wav', self::wav());

        $handler = new TranscriptionHandler(engine: $this->engine('the board agreed to defer', segments: [
            ['start' => 0, 'end' => 2, 'text' => 'the board agreed to defer'],
        ]));
        $worker = new Worker([JobQueue::TRANSCRIPTION => $handler] + Worker::defaultHandlers());
        $worker->run(10, 30);

        $this->assertCount(1, Connection::select('SELECT id FROM note_transcripts'));

        // A worker killed after writing the transcript has its job handed back
        // by the reaper, and the second attempt finds the row already there.
        // That second attempt must update it, not fall over on it.
        $jobId = (string) $this->queue->enqueue(Support::user('a'), JobQueue::TRANSCRIPTION, $note['id'], $attachment['id']);
        $tally = $worker->run(10, 30);

        $this->assertSame(0, $tally['failed'], 'the re-run did not fail');
        $this->assertSame(0, $tally['retried'] ?? 0, 'and did not throw its way into a retry');
        $this->assertSame('completed', (string) $this->job($jobId)['status']);
        $this->assertCount(1, Connection::select('SELECT id FROM note_transcripts'), 'still one row per attachment');
        $this->assertSame('completed', (string) Connection::selectOne(
            'SELECT processing_status FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        )['processing_status']);
    }
    public function testAFormatThisBuildCannotDecodeIsSkippedNotCalledCorrupt(): void
    {
        $note = $this->note();
        $attachment = $this->attach($note['id'], 'IMG_4021.heic', self::heic());
        $this->assertSame('image', $attachment['kind']);

        $tally = $this->worker->run(10, 30);
        $this->assertSame(0, $tally['failed'], 'a photo this host cannot read is not a failure');

        $thumbnail = Connection::selectOne(
            "SELECT status, permanent_failure, result->>'reason' AS reason
               FROM note_processing_jobs
              WHERE attachment_id = :id AND job_type = 'attachment.thumbnail'",
            ['id' => $attachment['id']],
        );
        $this->assertSame('completed', (string) $thumbnail['status']);
        $this->assertFalse((bool) $thumbnail['permanent_failure']);
        // HEIC has no reader in any PHP build, so "no dimensions" says nothing
        // about the bytes. Reporting it as `not_an_image` would put a red
        // failure on every photo taken with an iPhone.
        $this->assertSame('format_unsupported_by_gd', (string) $thumbnail['reason']);

        $row = Connection::selectOne(
            'SELECT processing_status, processing_error FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        );
        $this->assertSame('skipped', (string) $row['processing_status']);
        $this->assertNull($row['processing_error']);

        // And the file itself is untouched: it downloads exactly as uploaded.
        $this->assertTrue(is_file($this->storage . '/' . (string) Connection::selectOne(
            'SELECT storage_key FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        )['storage_key']));
    }
}
