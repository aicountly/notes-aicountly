<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

use Aicountly\Api\Support\Uuid;

/**
 * The incoming request, already parsed and validated at the edges.
 *
 * Controllers ask this object for typed values (`uuidParam`, `int`, `bool`)
 * instead of touching $_GET or the raw body, so "the client sent a string where
 * a number belongs" is one rejection here rather than a surprise three layers
 * down.
 */
final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $decodedBody = null;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $routeParams
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
        public readonly string $bearerToken,
        public array $routeParams = [],
    ) {
    }

    public static function fromGlobals(string $path, string $method, string $bearerToken): self
    {
        /** @var array<string, string> $query */
        $query = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $query[$key] = (string) $value;
            }
        }

        return new self($method, $path, $query, (string) file_get_contents('php://input'), $bearerToken);
    }

    /**
     * @param array<string, string> $query
     * @param string|null $rawBody Bypasses JSON encoding, so a test can send a
     *        malformed body — the one case `$body` cannot express.
     */
    public static function forTesting(
        string $method,
        string $path,
        array $query = [],
        mixed $body = null,
        string $bearerToken = '',
        ?string $rawBody = null,
    ): self {
        $raw = $rawBody ?? ($body === null ? '' : (string) json_encode($body));

        return new self($method, $path, $query, $raw, $bearerToken);
    }

    // -----------------------------------------------------------------------
    // Body
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function body(): array
    {
        if ($this->decodedBody !== null) {
            return $this->decodedBody;
        }

        if (trim($this->rawBody) === '') {
            return $this->decodedBody = [];
        }

        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw ApiException::badRequest('The request body is not valid JSON.');
        }

        return $this->decodedBody = $decoded;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body());
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->input($key);
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw ApiException::badRequest(sprintf('`%s` must be a string.', $key));
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int, mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);
        if (!is_array($value)) {
            throw ApiException::badRequest(sprintf('`%s` must be an array.', $key));
        }

        return array_values($value);
    }

    /** @return array<string, mixed> */
    public function object(string $key): array
    {
        $value = $this->input($key, []);
        if (!is_array($value)) {
            throw ApiException::badRequest(sprintf('`%s` must be an object.', $key));
        }

        return $value;
    }

    // -----------------------------------------------------------------------
    // Query string
    // -----------------------------------------------------------------------

    public function queryString(string $key, string $default = ''): string
    {
        return trim($this->query[$key] ?? $default);
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->query[$key] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    public function queryBool(string $key): ?bool
    {
        $raw = $this->query[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /** Comma-separated list, e.g. `?tags=gst,audit`. */
    public function queryList(string $key): array
    {
        $raw = $this->queryString($key);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }

    // -----------------------------------------------------------------------
    // Route parameters
    // -----------------------------------------------------------------------

    public function param(string $key): string
    {
        return $this->routeParams[$key] ?? '';
    }

    /**
     * A route parameter that must be a UUID.
     *
     * Rejecting the shape here is what keeps a malformed id from reaching a
     * query as a value Postgres then refuses with a 500.
     */
    public function uuidParam(string $key): string
    {
        $value = $this->param($key);
        if (!Uuid::isValid($value)) {
            throw ApiException::notFound('That item');
        }

        return strtolower($value);
    }
}
