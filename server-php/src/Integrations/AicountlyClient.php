<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\CompanyContext;
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
 * Two more things are written once here rather than in each adapter:
 *
 *   - **Where the product is.** Resolved through {@see SiblingApi} from this
 *     deployment's own hostname, so a sandbox deployment reaches sandbox
 *     siblings and nothing has to be configured per environment.
 *   - **Which company is being asked about.** `cmp_id` / `fy_id` / `bo_id` go
 *     on every outbound call when the inbound request carried them; see
 *     {@see CompanyContext}.
 *
 * The rest is the discipline {@see \Aicountly\Api\Domain\Ai\HttpPulseProvider}
 * already follows: short timeouts, no redirect following (a redirect would
 * carry the session key to whatever host it names), a bounded response, one
 * retry only for a connection that never opened, and **no response body in any
 * log line** — another product's error text quotes back what was asked about,
 * which here is somebody's contacts and calendar.
 *
 * ## Object storage is not a sibling product
 *
 * {@see putBytes()} and {@see fetchBytes()} exist for one thing. AICOUNTLY Drive
 * hands out **presigned S3 URLs**, and the bytes of an upload go straight to the
 * object store rather than through Drive's API — see
 * `drive-react-app/docs/UPLOAD_SAVE_FLOW.md`. That URL is not an AICOUNTLY
 * origin, so those two methods are built to be structurally incapable of
 * carrying an Authorization header: the signature is already *in* the URL, and a
 * ses_key on that request would hand a live AICOUNTLY session to a third-party
 * object store and to every proxy and access log between here and it. They are
 * separate methods rather than a flag on {@see send()} for exactly that reason —
 * there is no argument anyone can pass that turns the header back on.
 */
final class AicountlyClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    /** Bytes move slower than JSON, and one attachment may be tens of megabytes. */
    private const TRANSFER_TIMEOUT_SECONDS = 120;

    /** Nothing asked for here is large; a runaway answer is a fault, not a payload. */
    private const MAX_RESPONSE_BYTES = 1048576;

    /** The ceiling on a fetched object: well above the attachment limit, well below memory. */
    private const MAX_OBJECT_BYTES = 268435456;

    /**
     * @param string $service Short name used in error envelopes and log keys.
     * @param string $feature The {@see Features} flag that must be on.
     * @param string $product The sibling's name or `product_code`, resolved
     *        through {@see SiblingApi}. Not a URL and not an .env key: a
     *        deployment that wants to override the address sets
     *        `{PRODUCT}_API_ORIGIN`, and one that does not sets nothing.
     * @param HttpTransport $http The socket itself. Replaced in tests so the
     *        exact bytes of a request can be asserted on — including which
     *        headers are absent — and never replaced in production.
     */
    public function __construct(
        private readonly string $service,
        private readonly string $feature,
        private readonly string $product,
        private readonly HttpTransport $http = new CurlTransport(),
    ) {
    }

    /**
     * The base this client's paths hang off, `https://host/api`.
     *
     * Derived from this deployment's own hostname unless overridden, so a
     * sandbox deployment reaches sandbox siblings with nothing configured.
     */
    public function base(): string
    {
        return SiblingApi::apiBase($this->product);
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

        if (trim($sesKey) === '') {
            // Reached when something calls an integration outside a request —
            // a background worker, say. There is no honest token to substitute:
            // acting "as the system" is exactly the shared god-token this
            // client refuses to hold.
            throw ApiException::unauthenticated('This action needs your AICOUNTLY session.');
        }

        // Company context travels as cmp_id / fy_id / bo_id query parameters
        // across the suite — Pulse reads exactly those in
        // `BaseController::companyContext()`, and a sibling that scopes by
        // company answers for the wrong one, or for none, without them. They
        // are whatever arrived on the inbound request: this API does not invent
        // a company, and an explicit $query entry stays authoritative.
        $query += CompanyContext::params();

        $url = $base . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $payload = $body === null
            ? null
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $attempt = $this->execAuthenticated($method, $url, $sesKey, $payload);
        if ($attempt['connection_failed']) {
            // Worth one more try: nothing was delivered, so nothing can be
            // duplicated by repeating it.
            $attempt = $this->execAuthenticated($method, $url, $sesKey, $payload);
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

        // Two envelopes exist in the suite, and this is the one place that has
        // to know both:
        //
        //   {status: 1, data}     Pulse, Books, the portal — `status: 0` with a
        //                         `message` is the failure, and it often comes
        //                         back on HTTP 200, so the field has to be read
        //                         rather than the response code.
        //   {success: true, data} Drive (`SesAuthController::jsonSuccess`), and
        //                         Notes' own clients.
        //
        // Neither is guessed at: an envelope is recognised by its own key, and a
        // refusal is never unwrapped as a payload. That last part is the whole
        // point — treating `status: 0` as data is how a permission denial
        // reaches the UI as a row of nulls instead of an error.
        if (isset($decoded['status']) && is_numeric($decoded['status'])) {
            if ((int) $decoded['status'] !== 1) {
                Logger::warn($this->service . '.refused', ['method' => $method, 'status' => $status]);
                throw ApiException::upstream($this->service, sprintf(
                    '%s could not answer this request.',
                    ucfirst($this->service),
                ));
            }

            return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        }

        if (array_key_exists('success', $decoded)) {
            if ($decoded['success'] !== true) {
                Logger::warn($this->service . '.refused', ['method' => $method, 'status' => $status]);
                throw ApiException::upstream($this->service, sprintf(
                    '%s could not answer this request.',
                    ucfirst($this->service),
                ));
            }

            return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        }

        // A thin proxy in front of a product, or one that answers the payload
        // bare. Both are read; nothing else is guessed.
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    }

    // -----------------------------------------------------------------------
    // Presigned object storage
    //
    // Neither method below builds an Authorization header, and neither takes a
    // ses_key it could build one from. See the class note.
    // -----------------------------------------------------------------------

    /**
     * PUT bytes to a presigned URL a sibling product issued.
     *
     * One attempt only. A PUT is idempotent, but a transfer that timed out
     * part-way through is not "nothing was delivered", and the honest move is to
     * report the failure so the caller can abort its upload session — not to
     * race a second copy of tens of megabytes against the first.
     */
    public function putBytes(string $url, string $bytes, string $contentType): void
    {
        Features::require($this->feature);

        $attempt = $this->exec(
            'PUT',
            self::assertPresignedUrl($url),
            [
                // Exactly the type the URL was signed for and nothing else: an
                // object store rejects a PUT whose signed headers disagree with
                // what arrives.
                'Content-Type: ' . $contentType,
                // cURL adds `Expect: 100-continue` to a large body by itself,
                // and several S3 implementations answer 417 to it.
                'Expect:',
            ],
            $bytes,
            self::TRANSFER_TIMEOUT_SECONDS,
        );

        if ($attempt['connection_failed'] || $attempt['status'] < 200 || $attempt['status'] >= 300) {
            // No body in the log line: an object store quotes the key back in
            // its errors, and a key names the product, the tenant and the file.
            Logger::warn($this->service . '.object_put_failed', ['status' => $attempt['status']]);
            throw ApiException::upstream($this->service, 'The file could not be uploaded — please try again.');
        }
    }

    /** GET the bytes behind a presigned URL a sibling product issued. */
    public function fetchBytes(string $url): string
    {
        Features::require($this->feature);

        $attempt = $this->exec(
            'GET',
            self::assertPresignedUrl($url),
            ['Accept: */*'],
            null,
            self::TRANSFER_TIMEOUT_SECONDS,
            self::MAX_OBJECT_BYTES,
        );

        if ($attempt['status'] === 404 && !$attempt['connection_failed']) {
            throw ApiException::notFound('That file');
        }
        if ($attempt['connection_failed'] || $attempt['status'] < 200 || $attempt['status'] >= 300) {
            Logger::warn($this->service . '.object_get_failed', ['status' => $attempt['status']]);
            throw ApiException::upstream($this->service, 'The file could not be read — please try again.');
        }

        return $attempt['body'];
    }

    /**
     * A URL this API is willing to send bytes to, or read them from.
     *
     * The value arrives inside another product's JSON response, which makes it
     * data rather than configuration. `file://`, `gopher://` and a bare path
     * would each turn a confused or compromised sibling into a way to make this
     * server read its own disk.
     */
    private static function assertPresignedUrl(string $url): string
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (($scheme !== 'https' && $scheme !== 'http') || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw ApiException::upstream(
                'storage',
                'The storage service returned an address this server will not use.',
            );
        }

        return $url;
    }

    // -----------------------------------------------------------------------

    /**
     * One attempt, with the caller's session on it.
     *
     * @return array{connection_failed: bool, status: int, body: string}
     */
    private function execAuthenticated(string $method, string $url, string $sesKey, ?string $payload): array
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

        return $this->exec($method, $url, $headers, $payload);
    }

    /**
     * One attempt.
     *
     * `connection_failed` is the retryable case and is kept distinct from an
     * HTTP status: a socket-level failure and a 500 look alike to the transport,
     * and treating a 500 like a refused connection is how a struggling service
     * gets hit twice.
     *
     * @param array<int, string> $headers
     * @return array{connection_failed: bool, status: int, body: string}
     */
    private function exec(
        string $method,
        string $url,
        array $headers,
        ?string $payload,
        int $timeoutSeconds = self::REQUEST_TIMEOUT_SECONDS,
        int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
    ): array {
        return $this->http->send(
            $method,
            $url,
            $headers,
            $payload,
            self::CONNECT_TIMEOUT_SECONDS,
            $timeoutSeconds,
            $maxResponseBytes,
        );
    }
}
