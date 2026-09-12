<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Collaboration\PresenceService;
use Aicountly\Api\Domain\Jobs\Handlers\DerivedTextHandler;
use Aicountly\Api\Domain\Jobs\Handlers\EmbeddingHandler;
use Aicountly\Api\Domain\Jobs\Handlers\ObjectPurgeHandler;
use Aicountly\Api\Domain\Jobs\Handlers\OcrHandler;
use Aicountly\Api\Domain\Jobs\Handlers\TextExtractionHandler;
use Aicountly\Api\Domain\Jobs\Handlers\ThumbnailHandler;
use Aicountly\Api\Domain\Jobs\Handlers\TranscriptionHandler;
use Aicountly\Api\Domain\Notes\NotesService;
use Aicountly\Api\Http\RateLimiter;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;

/**
 * One bounded pass over the queue, then exit.
 *
 * Shaped by where it has to run: a cPanel account has cron and no supervisor,
 * so a long-lived daemon is not an option and a run that overruns its slot is
 * how two workers end up in the same minute. Hence `--max` and `--timeout`, and
 * hence a run that finishes cleanly rather than looping — the next minute's
 * cron is the loop.
 *
 * It claims **one job at a time**. Claiming ten would be one query instead of
 * ten, and would also mean that a worker killed on job three leaves seven rows
 * locked until the reaper notices. Cheap query, better failure mode.
 *
 * Housekeeping runs first, because it is the part that must not be skipped:
 * expired trash, stale rate-limit windows, dead sessions and jobs whose worker
 * vanished. If the queue is busy for an hour, that work has still been done.
 */
final class Worker
{
    public const DEFAULT_BATCH = 25;
    public const DEFAULT_TIMEOUT_SECONDS = 55;

    /** @var array<string, JobHandler> */
    private readonly array $handlers;

    private readonly string $workerId;

    /** @param array<string, JobHandler>|null $handlers Overridden in tests; production takes the defaults. */
    public function __construct(
        ?array $handlers = null,
        private readonly JobQueue $queue = new JobQueue(),
        private readonly AttachmentService $attachments = new AttachmentService(),
    ) {
        $this->handlers = $handlers ?? self::defaultHandlers();
        $this->workerId = Str::limit(gethostname() . ':' . getmypid(), 64);
    }

    /** @return array<string, JobHandler> */
    public static function defaultHandlers(): array
    {
        return [
            JobQueue::THUMBNAIL => new ThumbnailHandler(),
            JobQueue::TEXT_EXTRACTION => new TextExtractionHandler(),
            JobQueue::OCR => new OcrHandler(),
            JobQueue::TRANSCRIPTION => new TranscriptionHandler(),
            JobQueue::DERIVED_TEXT => new DerivedTextHandler(),
            JobQueue::EMBEDDING => new EmbeddingHandler(),
            JobQueue::OBJECT_PURGE => new ObjectPurgeHandler(),
        ];
    }

    /**
     * Work until the batch is done, the queue is empty, or the clock runs out.
     *
     * @return array<string, int> What happened, for the cron log.
     */
    public function run(int $max = self::DEFAULT_BATCH, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + max(1, $timeoutSeconds);

        $tally = ['claimed' => 0, 'completed' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0];

        while ($tally['claimed'] < max(1, $max)) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $jobs = $this->queue->claim($this->workerId, 1);
            if ($jobs === []) {
                break;
            }

            $tally['claimed']++;
            $outcome = $this->process($jobs[0]);
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        $tally['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $tally;
    }

    /**
     * The upkeep that has nothing to do with attachments but has nowhere else
     * to run on a host with one cron entry.
     *
     * @return array<string, int>
     */
    public function maintenance(): array
    {
        return [
            // Trash is a promise with a deadline: "deleted" has to become
            // deleted, or the retention window is decoration.
            'trash_purged' => (new NotesService())->purgeExpiredTrash(),
            'rate_limits_swept' => RateLimiter::sweep(),
            // A cached portal session outliving its expiry would be an
            // authentication decision made by a stale row.
            'sessions_expired' => Connection::execute('DELETE FROM api_sessions WHERE expires_at < now()'),
            'jobs_reaped' => $this->queue->reapStuck(),
            // A tab that closes without calling DELETE /notes/{id}/presence
            // (a crash, a lost connection) already ages out of every live
            // viewer list within seconds; this is only cleaning up the row
            // itself so the table does not grow with every note anyone has
            // ever opened.
            'presence_swept' => (new PresenceService())->sweep(),
        ];
    }

    /**
     * Run one claimed job and record what it did.
     *
     * @param array<string, mixed> $job
     * @return string One of completed | skipped | failed | retried.
     */
    private function process(array $job): string
    {
        $jobId = (string) $job['id'];
        $type = (string) $job['job_type'];
        $handler = $this->handlers[$type] ?? null;

        if ($handler === null) {
            // A job type nobody can run will not become runnable by waiting.
            $this->queue->failPermanently($jobId, 'unknown_job_type');
            $this->refresh($job);

            return 'failed';
        }

        try {
            $result = $handler->handle($job);
        } catch (\Throwable $e) {
            $status = $this->queue->retry($jobId, self::reason($e));
            Logger::warn('job.retry', ['job_type' => $type, 'error' => get_debug_type($e), 'status' => $status]);
            $this->refresh($job);

            return $status === 'failed' ? 'failed' : 'retried';
        }

        $outcome = (string) ($result['outcome'] ?? JobHandler::COMPLETED);
        $reason = (string) ($result['reason'] ?? $outcome);

        match ($outcome) {
            JobHandler::SKIPPED => $this->queue->skip($jobId, $reason),
            JobHandler::PERMANENT_FAILURE => $this->queue->failPermanently($jobId, $reason),
            default => $this->queue->complete($jobId, $result),
        };

        $this->refresh($job);

        return match ($outcome) {
            JobHandler::SKIPPED => 'skipped',
            JobHandler::PERMANENT_FAILURE => 'failed',
            default => 'completed',
        };
    }

    /** Keep the attachment's single status column true to the jobs behind it. */
    private function refresh(array $job): void
    {
        $attachmentId = $job['attachment_id'] ?? null;
        if (is_string($attachmentId)) {
            $this->attachments->refreshProcessingStatus($attachmentId);
        }
    }

    /**
     * A failure reason safe to store.
     *
     * A PDOException quotes the statement it failed on, and a statement can
     * carry a note's text — so that class contributes its code and nothing
     * else. `last_error` is read by support staff; it is not a place for
     * somebody's writing.
     */
    private static function reason(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            return 'database_error:' . Str::limit((string) $e->getCode(), 10);
        }

        return Str::limit(get_debug_type($e) . ': ' . $e->getMessage(), 300);
    }
}
