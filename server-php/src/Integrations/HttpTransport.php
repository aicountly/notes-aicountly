<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

/**
 * One HTTP exchange, with no opinion about what is being said.
 *
 * This exists so {@see AicountlyClient} can be driven by a test without a
 * socket. It is deliberately the *lowest* seam available: the headers, the
 * timeouts and the decision to send or withhold an Authorization header are all
 * made above it, so a fake implementation sees exactly the bytes a real request
 * would have carried — which is the only way a test can prove that the caller's
 * ses_key is *not* on the wire to an object store.
 */
interface HttpTransport
{
    /**
     * @param array<int, string> $headers Complete `Name: value` lines.
     * @param string|null $body Request body, or null for a bodyless method.
     * @return array{connection_failed: bool, status: int, body: string}
     *         `connection_failed` is the retryable case — DNS, TCP, TLS or a
     *         timeout — and is kept apart from an HTTP status, because treating
     *         a 500 like a refused connection is how a struggling service gets
     *         hit twice.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array;
}
