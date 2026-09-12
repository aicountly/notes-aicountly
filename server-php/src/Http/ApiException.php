<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

/**
 * An error with a stable machine-readable code.
 *
 * Every failure the client is meant to handle differently gets its own code, so
 * the frontend branches on `error.code` and never on a message string.
 */
class ApiException extends \RuntimeException
{
    /** @var array<string, mixed> */
    public readonly array $details;

    public function __construct(
        public readonly int $status,
        /** Stable machine-readable code. Named `errorCode` because \Exception already owns `$code`. */
        public readonly string $errorCode,
        string $message,
        array $details = [],
    ) {
        parent::__construct($message);
        $this->details = $details;
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'BAD_REQUEST', $message, $details);
    }

    public static function validation(array $errors): self
    {
        return new self(422, 'VALIDATION_FAILED', 'Some fields need attention.', ['fields' => $errors]);
    }

    public static function unauthenticated(string $message = 'Sign in to continue.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $what = 'That item'): self
    {
        return new self(404, 'NOT_FOUND', $what . ' could not be found.');
    }

    /**
     * The note changed underneath this edit.
     *
     * Carries the server's current version so the client can offer a recovery
     * path rather than silently discarding one side.
     */
    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'VERSION_CONFLICT', $message, $details);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(429, 'RATE_LIMITED', 'Too many requests — try again shortly.', [
            'retry_after' => $retryAfterSeconds,
        ]);
    }

    public static function featureDisabled(string $feature): self
    {
        return new self(503, 'FEATURE_DISABLED', 'This feature is not enabled on this deployment.', [
            'feature' => $feature,
        ]);
    }

    public static function upstream(string $service, string $message): self
    {
        return new self(502, 'UPSTREAM_UNAVAILABLE', $message, ['service' => $service]);
    }
}
