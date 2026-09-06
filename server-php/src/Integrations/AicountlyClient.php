<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * One HTTP call to another AICOUNTLY product.
 *
 * Calendar, Contacts and Connect are separate services with separate databases
 * and their own sharing rules. This client exists so all three are reached the
 * same way, and so the rule that matters is written once:
 *
 *   **The caller's own ses_key is what goes on the wire.** Not a service token,
 *   not an API key this deployment holds. Contacts decides whether *this
 *   person* may read that contact, exactly as it would if they had asked it
 *   directly. A shared god-token would make every Notes user as powerful as the
 *   integration itself — one link endpoint away from reading a directory they
 *   have no access to — and no amount of checking on this side would fix it,
 *   because this side does not hold the other product's sharing rules.
 *
 * The rest is the discipline {@see \Aicountly\Api\Domain\Ai\HttpPulseProvider}
 * already follows: short timeouts, no redirect following (a redirect would
 * carry the session key to whatever host it names), a bounded response, one
 * retry only for a connection that never opened, and **no response body in any
 * log line** — another product's error text quotes back what was asked about,
 * which here is somebody's contacts and calendar.
 */
final class AicountlyClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    /** Nothing asked for here is large; a runaway answer is a fault, not a payload. */
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * @param string $service Short name used in error envelopes and log keys.
     * @param string $feature The {@see Features} flag that must be on.
     * @param string $baseUrlKey The .env key holding that product's API base.
     */
    public function __construct(
        private readonly string $service,
        private readonly string $feature,
        private readonly string $baseUrlKey,
    ) {
    }

    public function base(): string
    {
        return rtrim(trim(Env::get($this->baseUrlKey)), '/');
    }

    /** Escape an id from a request before it becomes part of a path. */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Call the product and return the `data` it answered with.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    public function send(
        string $method,
        string $path,
        string $sesKey,
        ?array $body = null,
        array $query = [],
    ): array {
        // The flag first, always. A deployment that never configured this
        // product answers 503 without opening a socket or touching a row.
        Features::require($this->feature);

        $base = $this->base();
        if ($base === '') {
            // Only reachable if the flag was forced on without the URL; the
            // honest answer is the one Features would have given.
            throw ApiException::featureDisabled($this->feature);
        }

        if (trim($sesKey) === '') {
            // Reached when something calls an integration outside a request —
            // a background worker, say. There is no honest token to substitute:
            // acting "as the system" is exactly the shared god-token this
            // client refuses to hold.
            throw ApiException::unauthenticated('This action needs your AICOUNTLY session.');
        }

        $url = $base . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $payload = $body === null
            ? null
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $attempt = $this->exec($method, $url, $sesKey, $payload);
        if ($attempt['connection_failed']) {
            // Worth one more try: nothing was delivered, so nothing can be
            // duplicated by repeating it.
            $attempt = $this->exec($method, $url, $sesKey, $payload);
        }

        return $this->interpret($method, $attempt);
    }

    // -----------------------------------------------------------------------

    /**
     * @param array{connection_failed: bool, status: int, body: string} $attempt
     * @return array<string, mixed>
     */
    private function interpret(string $method, array $attempt): array
    {
        $status = $attempt['status'];

        if ($attempt['connection_failed']) {
            Logger::warn($this->service . '.unreachable', ['method' => $method]);
            throw ApiException::upstream($this->service, sprintf(
                '%s could not be reached — please try again.',
                ucfirst($this->service),
            ));
        }

        if ($status === 401) {
            // The other product rejected the session, not the request. Named
            // rather than reported as "your session has expired", because the
            // Notes session plainly has not — this one call was refused, and a
            // client told otherwise would sign the user out over it.
            throw ApiException::unauthenticated(sprintf(
                '%s did not accept your AICOUNTLY session.',
                ucfirst($this->service),
            ));
        }

        if ($status === 403 || $status === 404) {
            // That product decides. "Not yours" and "not there" are one answer
            // here for the same reason they are one answer for a note.
            throw ApiException::notFound('That ' . $this->service . ' record');
        }

        if ($status < 200 || $status >= 300) {
            Logger::warn($this->service . '.upstream_error', ['method' => $method, 'status' => $status]);
            throw ApiException::upstream($this->service, sprintf(
                '%s could not answer this request.',
                ucfirst($this->service),
            ));
        }

        $decoded = json_decode($attempt['body'], true);
        if (!is_array($decoded)) {
            Logger::warn($this->service . '.unparseable_response', ['method' => $method, 'status' => $status]);
            throw ApiException::upstream($this->service, sprintf(
                '%s returned something this server could not read.',
                ucfirst($this->service),
            ));
        }

        // Every AICOUNTLY product wraps its payload in {success, data}; a thin
        // proxy in front of one may not. Both are read, nothing else is guessed.
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    }

    /**
     * One attempt.
     *
     * `connection_failed` is the retryable case and is kept distinct from an
     * HTTP status: cURL reports both as a failed `exec`, and treating a 500
     * like a refused connection is how a struggling service gets hit twice.
     *
     * @return array{connection_failed: bool, status: int, body: string}
     */
    private function exec(string $method, string $url, string $sesKey, ?string $payload): array
    {
        $headers = [
            'Accept: application/json',
            // The caller's session, forwarded unchanged. See the class note.
            'Authorization: Bearer ' . $sesKey,
            // Lets the other product attribute the call in its own audit log.
            'X-Aicountly-Client: notes',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['connection_failed' => true, 'status' => 0, 'body' => ''];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_HEADER => false,
            // A redirect can point anywhere, and following it would hand the
            // caller's ses_key to whatever host it names.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($resource, $downloadSize, $downloaded): int
                => $downloaded > self::MAX_RESPONSE_BYTES ? 1 : 0,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($handle);

        return [
            // No status at all means the exchange never got as far as an
            // answer: DNS, TCP, TLS or a timeout.
            'connection_failed' => $failed && $status === 0,
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }
}
