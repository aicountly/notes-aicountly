<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use Aicountly\Api\Env;

/**
 * Structured application log.
 *
 * The rule this class exists to enforce: **note content never reaches a log
 * line.** Ids, counts, durations and error classes are fine; titles, document
 * JSON, extracted text, transcripts, comment bodies and AI prompts are not.
 * `scrub()` drops the keys that carry them so a careless caller downstream
 * cannot leak a note body into a file the whole hosting account can read.
 */
final class Logger
{
    /** Keys whose values are user content and are dropped, never truncated. */
    private const REDACTED_KEYS = [
        'title', 'body', 'text', 'content', 'document', 'document_json',
        'extracted_text', 'derived_text', 'transcript', 'chunk_text', 'prompt',
        'answer', 'summary', 'snippet', 'query', 'q', 'password', 'token',
        'auth_token', 'ses_key', 'authorization', 'api_key', 'secret',
    ];

    private static ?string $requestId = null;

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warn(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        if ($level === 'info' && Env::get('APP_DEBUG') !== 'true') {
            return;
        }

        $line = json_encode([
            'ts' => Clock::iso(),
            'level' => $level,
            'request_id' => self::requestId(),
            'message' => $message,
            'context' => self::scrub($context),
        ], JSON_UNESCAPED_SLASHES);

        error_log('[notes] ' . (string) $line);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function scrub(array $context, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['_' => 'truncated'];
        }

        $safe = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::scrub($value, $depth + 1);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? Str::limit($value, 200) : $value;
                continue;
            }
            $safe[$key] = get_debug_type($value);
        }

        return $safe;
    }
}
