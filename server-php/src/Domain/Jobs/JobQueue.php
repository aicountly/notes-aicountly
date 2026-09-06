<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * The background work queue, in PostgreSQL.
 *
 * There is no Redis, no Beanstalk and no daemon on a cPanel account — there is
 * a cron entry and a database. That is enough, provided the claim is correct,
 * so this class is mostly about one statement:
 *
 *   `SELECT … FOR UPDATE SKIP LOCKED`
 *
 * Without SKIP LOCKED two workers started by two overlapping cron runs either
 * block on each other (the slow failure) or both run the same job (the
 * expensive one — two OCR calls, two charges, two rollups of the same text).
 * With it, the second worker steps over rows the first has locked and takes the
 * next job instead.
 *
 * The other three rules a queue needs to be trustworthy:
 *
 *   - **Retries back off.** A failing job that retries immediately is a way to
 *     hammer an upstream that is already struggling.
 *   - **Some failures never retry.** An unsupported file type and a deleted
 *     note will not succeed on the fifth attempt either, so they are marked
 *     `permanent_failure` and stop.
 *   - **A job that cannot run is not a failure.** OCR with no engine configured
 *     is skipped, and skipping is a completed outcome, not an error to page
 *     someone about at 3am.
 */
final class JobQueue
{
    public const THUMBNAIL = 'attachment.thumbnail';
    public const TEXT_EXTRACTION = 'attachment.text_extraction';
    public const OCR = 'attachment.ocr';
    public const TRANSCRIPTION = 'attachment.transcription';
    public const DERIVED_TEXT = 'note.derived_text';
    public const OBJECT_PURGE = 'attachment.object_purge';

    /**
     * Lower runs first — the claim orders by `priority, available_at`, and the
     * partial index on those two columns is what keeps the claim off a table
     * scan once the queue has a million finished rows in it.
     *
     * The ordering is a product decision: a thumbnail is what the user is
     * staring at, so it outranks the OCR of the same image.
     */
    private const PRIORITIES = [
        self::THUMBNAIL => 3,
        self::DERIVED_TEXT => 4,
        self::TEXT_EXTRACTION => 5,
        self::OCR => 7,
        self::TRANSCRIPTION => 8,
        self::OBJECT_PURGE => 9,
    ];

    /** Retry schedule, in seconds: 30s, 2m, 8m, 32m, then hourly. */
    private const BACKOFF_BASE_SECONDS = 30;
    private const BACKOFF_MAX_SECONDS = 3600;

    /** A worker that dies mid-job leaves the row locked; this is how long before it is taken back. */
    private const STUCK_AFTER_MINUTES = 15;

    /**
     * Add a job, unless the same work is already waiting.
     *
     * De-duplication matters most for {@see DERIVED_TEXT}: every handler that
     * produces text asks for a rollup, and three attachments finishing together
     * should rebuild the note's search text once, not three times.
     *
     * The identity of a job is the note and the attachment it names, so a job
     * that names **neither** has no identity to be a duplicate of and is always
     * inserted. {@see OBJECT_PURGE} is the case: it deliberately references no
     * row (see {@see \Aicountly\Api\Domain\Attachments\AttachmentService::delete()})
     * and carries the keys to remove in its payload, so two detached files are
     * two different jobs that happen to look alike. Folding the second into the
     * first would leave that file on the disk for good — a "deleted" attachment
     * whose bytes are still there is the one outcome this queue must not
     * produce.
     *
     * @param array<string, mixed> $payload Ids, keys and counts only — never note content.
     * @return string|null The job id, or null when an identical job was already queued.
     */
    public function enqueue(
        Identity|string $requestedBy,
        string $jobType,
        ?string $noteId = null,
        ?string $attachmentId = null,
        array $payload = [],
        int $delaySeconds = 0,
    ): ?string {
        $id = Uuid::v4();
        $actor = $requestedBy instanceof Identity ? $requestedBy->userId : $requestedBy;
        $tenant = $requestedBy instanceof Identity ? $requestedBy->tenantId : null;

        $sql = 'INSERT INTO note_processing_jobs
                (id, job_type, note_id, attachment_id, tenant_id, requested_by,
                 status, priority, payload, available_at)
             SELECT :id::uuid, :job_type::text, :note_id::uuid, :attachment_id::uuid, :tenant, :actor,
                    \'queued\', :priority, :payload::jsonb, now() + make_interval(secs => :delay)';

        if ($noteId !== null || $attachmentId !== null) {
            $sql .= ' WHERE NOT EXISTS (
                SELECT 1 FROM note_processing_jobs q
                WHERE q.job_type = :job_type::text
                  AND q.status IN (\'queued\', \'processing\')
                  AND q.attachment_id IS NOT DISTINCT FROM :attachment_id::uuid
                  AND q.note_id IS NOT DISTINCT FROM :note_id::uuid
             )';
        }

        $inserted = Connection::execute(
            $sql,
            [
                'id' => $id,
                'job_type' => $jobType,
                'note_id' => $noteId,
                'attachment_id' => $attachmentId,
                'tenant' => $tenant,
                'actor' => Str::limit($actor, 64),
                'priority' => self::PRIORITIES[$jobType] ?? 5,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'delay' => max(0, $delaySeconds),
            ],
        );

        return $inserted === 1 ? $id : null;
    }

    /**
     * Take up to `$limit` due jobs for this worker.
     *
     * One statement, deliberately: claiming with a SELECT and then marking with
     * an UPDATE leaves a window in which a second worker sees the same row as
     * available. The CTE locks the rows it selects and the UPDATE consumes them
     * in the same transaction, so a claimed job is claimed the moment it is
     * visible as such.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(string $workerId, int $limit = 1): array
    {
        return Connection::select(
            'WITH due AS (
                SELECT id FROM note_processing_jobs
                WHERE status = \'queued\' AND available_at <= now()
                ORDER BY priority, available_at
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
             )
             UPDATE note_processing_jobs j
                SET status = \'processing\',
                    attempts = j.attempts + 1,
                    locked_at = now(),
                    locked_by = :worker,
                    started_at = coalesce(j.started_at, now()),
                    updated_at = now()
             FROM due
             WHERE j.id = due.id
             RETURNING j.id, j.job_type, j.note_id, j.attachment_id, j.tenant_id, j.requested_by,
                       j.attempts, j.max_attempts, j.payload',
            ['limit' => max(1, $limit), 'worker' => Str::limit($workerId, 64)],
        );
    }

    /** @param array<string, mixed> $result */
    public function complete(string $jobId, array $result = []): void
    {
        $this->finish($jobId, 'completed', $result, null, false);
    }

    /**
     * The job ran and correctly decided there was nothing to do.
     *
     * The table's status check constraint has no `skipped` value, so the status
     * stays `completed` and the outcome lives in `result`. That keeps "did this
     * job finish?" and "did this job do anything?" as two separate questions,
     * which is what an operator actually needs to answer.
     */
    public function skip(string $jobId, string $reason): void
    {
        $this->finish($jobId, 'completed', ['outcome' => 'skipped', 'reason' => $reason], null, false);
    }

    /** Will never succeed: unsupported file type, missing note, unknown job type. */
    public function failPermanently(string $jobId, string $reason): void
    {
        $this->finish($jobId, 'failed', ['outcome' => 'permanent_failure', 'reason' => $reason], $reason, true);
    }

    /**
     * Something went wrong that might not next time.
     *
     * Returns the status the job ended up in: `queued` when there are attempts
     * left, `failed` when they are spent.
     */
    public function retry(string $jobId, string $reason): string
    {
        $row = Connection::selectOne(
            'UPDATE note_processing_jobs
                SET status = CASE WHEN attempts >= max_attempts THEN \'failed\' ELSE \'queued\' END,
                    available_at = now() + make_interval(secs => :backoff),
                    finished_at = CASE WHEN attempts >= max_attempts THEN now() ELSE NULL END,
                    last_error = :reason,
                    locked_at = NULL,
                    locked_by = NULL,
                    updated_at = now()
              WHERE id = :id AND status = \'processing\'
              RETURNING status, attempts',
            [
                'id' => $jobId,
                'reason' => Str::limit($reason, 500),
                'backoff' => $this->backoffFor($jobId),
            ],
        );

        return (string) ($row['status'] ?? 'failed');
    }

    /**
     * Give back jobs whose worker never came home.
     *
     * A cron-run worker can be killed by a deploy, a memory limit or the
     * hosting account's process reaper part-way through a job. Without this the
     * row stays `processing` forever and the attachment never leaves
     * "processing" in the UI.
     */
    public function reapStuck(): int
    {
        return Connection::execute(
            'UPDATE note_processing_jobs
                SET status = CASE WHEN attempts >= max_attempts THEN \'failed\' ELSE \'queued\' END,
                    available_at = now(),
                    last_error = \'worker_vanished\',
                    locked_at = NULL,
                    locked_by = NULL,
                    updated_at = now()
              WHERE status = \'processing\'
                AND locked_at < now() - make_interval(mins => :minutes)',
            ['minutes' => self::STUCK_AFTER_MINUTES],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $jobId): ?array
    {
        return Connection::selectOne(
            'SELECT id, job_type, note_id, attachment_id, status, attempts, max_attempts,
                    permanent_failure, last_error, payload, result, available_at, finished_at
             FROM note_processing_jobs WHERE id = :id',
            ['id' => $jobId],
        );
    }

    /**
     * Queue depth by status, for the health endpoint and for a human wondering
     * whether the cron is running at all.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $rows = Connection::select(
            'SELECT status, count(*) AS total FROM note_processing_jobs
             WHERE created_at > now() - interval \'7 days\' GROUP BY status',
        );

        $stats = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }

        return $stats;
    }

    /** Exponential, capped, and computed from the attempts already made. */
    private function backoffFor(string $jobId): int
    {
        $row = Connection::selectOne(
            'SELECT attempts FROM note_processing_jobs WHERE id = :id',
            ['id' => $jobId],
        );
        $attempts = max(1, (int) ($row['attempts'] ?? 1));

        return (int) min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * (4 ** ($attempts - 1)));
    }

    /** @param array<string, mixed> $result */
    private function finish(string $jobId, string $status, array $result, ?string $error, bool $permanent): void
    {
        Connection::execute(
            'UPDATE note_processing_jobs
                SET status = :status::text,
                    result = :result::jsonb,
                    last_error = :error,
                    permanent_failure = :permanent,
                    finished_at = now(),
                    locked_at = NULL,
                    locked_by = NULL,
                    updated_at = now()
              WHERE id = :id',
            [
                'id' => $jobId,
                'status' => $status,
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES),
                'error' => $error === null ? null : Str::limit($error, 500),
                'permanent' => $permanent,
            ],
        );
    }
}
