<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Logger;

/**
 * Fixed-window rate limiting, per user and per bucket.
 *
 * The buckets exist so the expensive things can be limited without limiting
 * typing. An AI call, a bulk import or a transcription job costs real money and
 * real seconds; an autosave costs a row update, and a note-taking app that
 * answers 429 while someone is writing has failed at its one job. So `notes`
 * writes are not rate limited here at all, and `ai`, `upload` and `search` are.
 */
final class RateLimiter
{
    /** bucket => [max hits, window seconds]. */
    private const BUCKETS = [
        'ai' => [40, 300],
        'ai_heavy' => [10, 300],
        'search' => [120, 60],
        'semantic' => [30, 300],
        'upload' => [60, 300],
        'import' => [5, 3600],
        'share' => [60, 3600],
        // A heartbeat per open note every ~8s. Several tabs on several
        // notes at once is normal; a client stuck in a tight retry loop is
        // what this catches. 60/min is generous room above real use and
        // still a real ceiling.
        'presence' => [60, 60],
    ];

    public static function hit(string $bucket, string $userId): void
    {
        [$max, $window] = self::BUCKETS[$bucket] ?? [null, null];
        if ($max === null) {
            return;
        }

        $key = $bucket . ':' . hash('sha256', $userId);

        try {
            // One statement: insert the window or bump it, and read the count
            // back. Two statements would let concurrent requests both see a
            // fresh window and both reset the counter.
            $row = Connection::selectOne(
                'INSERT INTO api_rate_limits (bucket_key, hits, window_start, expires_at)
                 VALUES (:key, 1, now(), now() + make_interval(secs => :window))
                 ON CONFLICT (bucket_key) DO UPDATE SET
                    hits = CASE WHEN api_rate_limits.expires_at < now() THEN 1
                                ELSE api_rate_limits.hits + 1 END,
                    window_start = CASE WHEN api_rate_limits.expires_at < now() THEN now()
                                        ELSE api_rate_limits.window_start END,
                    expires_at = CASE WHEN api_rate_limits.expires_at < now()
                                      THEN now() + make_interval(secs => :window)
                                      ELSE api_rate_limits.expires_at END
                 RETURNING hits, extract(epoch FROM (expires_at - now())) AS retry_after',
                ['key' => $key, 'window' => $window],
            );
        } catch (\Throwable $e) {
            // A limiter that cannot reach its table must not take the API down
            // with it; the request proceeds unlimited and the failure is logged.
            Logger::warn('ratelimit.unavailable', ['bucket' => $bucket, 'error' => get_debug_type($e)]);

            return;
        }

        if ($row !== null && (int) $row['hits'] > $max) {
            throw ApiException::rateLimited(max(1, (int) ceil((float) $row['retry_after'])));
        }
    }

    /** Housekeeping for the worker: drop windows that closed long ago. */
    public static function sweep(): int
    {
        return Connection::execute("DELETE FROM api_rate_limits WHERE expires_at < now() - interval '1 hour'");
    }
}
