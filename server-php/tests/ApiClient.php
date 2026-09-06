<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\CompanyContext;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Http\Router;
use Aicountly\Api\Routes;

/**
 * Drives the real router, controllers and database in-process.
 *
 * It stops one step short of the socket: `SessionGuard` is bypassed by handing
 * the handler an {@see Identity} directly, because calling the live AICOUNTLY
 * portal from a test suite would make the suite depend on a service being up.
 * Everything after authentication — routing, permissions, tenancy, SQL — is the
 * production path, so a test that passes here exercises what a request does.
 */
final class ApiClient
{
    /** Stands in for the ses_key the front controller would have validated. */
    public const SES_KEY = 'ses_test_key';

    private Router $router;

    public function __construct(private readonly Identity $identity)
    {
        $this->router = new Router();
        Routes::register($this->router);
    }

    public function as(Identity $identity): self
    {
        return new self($identity);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path, $query, null);
    }

    public function post(string $path, mixed $body = null, array $query = []): array
    {
        return $this->call('POST', $path, $query, $body);
    }

    public function patch(string $path, mixed $body = null): array
    {
        return $this->call('PATCH', $path, [], $body);
    }

    public function delete(string $path, array $query = []): array
    {
        return $this->call('DELETE', $path, $query, null);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function call(string $method, string $path, array $query, mixed $body): array
    {
        // The router works on the normalised path the front controller produces.
        $normalised = strtolower(trim($path, '/'));

        // Controllers read the query string through Request, which reads $_GET.
        $previousGet = $_GET;
        $_GET = array_map('strval', $query);

        try {
            $matched = $this->router->match($method, $normalised);
            if ($matched === null) {
                return ['status' => 404, 'body' => ['success' => false, 'error' => ['code' => 'NOT_FOUND']]];
            }

            // A synthetic ses_key. Every real request behind the router carries
            // one, and an integration that forwards the caller's session to a
            // sibling refuses an empty one — so a test client without a token
            // could not reach those paths at all.
            $request = Request::forTesting(
                $method,
                $normalised,
                array_map('strval', $query),
                $body,
                self::SES_KEY,
            );
            $request->routeParams = $matched['params'];

            // The front controller does this too; without it a test could not
            // observe company context reaching a sibling call.
            CompanyContext::capture($request->query);

            /** @var Response $response */
            $response = ($matched['handler'])($request, $this->identity);

            return self::capture($response);
        } catch (ApiException $e) {
            return self::capture(Response::error($e));
        } finally {
            $_GET = $previousGet;
            CompanyContext::clear();
        }
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function capture(Response $response): array
    {
        // Response::send() writes headers; in CLI that is a no-op warning-free
        // path, but the body is what a test asserts on, so it is re-encoded
        // here rather than captured from the output buffer.
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('body');
        $body = $property->getValue($response);

        return [
            'status' => $response->status,
            'body' => is_array($body) ? $body : [],
        ];
    }

    /** Convenience: the `data` of a successful call, or a failure. */
    public function data(array $result): mixed
    {
        return $result['body']['data'] ?? null;
    }
}
