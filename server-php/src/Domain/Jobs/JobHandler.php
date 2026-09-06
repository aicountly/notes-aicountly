<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs;

/**
 * One kind of background work.
 *
 * A handler reports its outcome by returning it rather than by throwing, for
 * everything it can reason about: "there is no OCR engine configured" and "this
 * file type will never yield text" are ordinary answers, and a queue that
 * cannot tell them apart from a network blip will retry both five times and
 * then page someone.
 *
 * A thrown exception means the opposite — something unexpected and possibly
 * transient — and {@see Worker} treats it as retryable with backoff. That is
 * the whole contract:
 *
 *   return COMPLETED          → done, with whatever it produced
 *   return SKIPPED            → nothing to do; do not retry, do not alarm
 *   return PERMANENT_FAILURE  → will never succeed; do not retry
 *   throw                     → try again later
 */
interface JobHandler
{
    public const COMPLETED = 'completed';
    public const SKIPPED = 'skipped';
    public const PERMANENT_FAILURE = 'permanent_failure';

    /**
     * @param array<string, mixed> $job The claimed row: id, job_type, note_id, attachment_id, payload.
     * @return array{outcome: string, reason?: string, ...} Ids and counts only — never note content.
     */
    public function handle(array $job): array;
}
