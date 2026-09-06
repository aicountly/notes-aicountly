<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

use Aicountly\Api\Support\Logger;

/**
 * The one place this API writes a response.
 *
 * Every body has the same envelope — `{success, data}` or `{success, error}` —
 * so the frontend's API client has exactly one shape to unwrap and exactly one
 * place to look for an error code.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        public readonly int $status,
        private readonly mixed $body,
    ) {
    }

    public static function ok(mixed $data, array $meta = []): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self(200, $payload);
    }

    public static function created(mixed $data): self
    {
        return new self(201, ['success' => true, 'data' => $data]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function error(ApiException $e): self
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->details !== []) {
            $error['details'] = $e->details;
        }

        return new self($e->status, ['success' => false, 'error' => $error]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = new self($this->status, $this->body);
        $clone->headers = $this->headers + [$name => $value];

        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-Id: ' . Logger::requestId());
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->status === 204 || $this->body === null) {
            return;
        }

        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
