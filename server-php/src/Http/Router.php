<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

/**
 * Pattern router.
 *
 * Routes are declared as `/notes/{id}/attachments/{attachmentId}`; a segment in
 * braces becomes a route parameter. Matching is exact on the rest, so a path
 * that is not declared is a 404 rather than something a controller has to
 * defend against.
 */
final class Router
{
    /** @var array<int, array{method: string, segments: array<int, string>, handler: callable, auth: bool}> */
    private array $routes = [];

    /**
     * @param callable(Request, \Aicountly\Api\Auth\Identity): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'segments' => self::split($pattern),
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function get(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('GET', $pattern, $handler, $auth);
    }

    public function post(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('POST', $pattern, $handler, $auth);
    }

    public function patch(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('PATCH', $pattern, $handler, $auth);
    }

    public function delete(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('DELETE', $pattern, $handler, $auth);
    }

    /**
     * @return array{handler: callable, params: array<string, string>, auth: bool}|null
     */
    public function match(string $method, string $path): ?array
    {
        $parts = self::split($path);
        $method = strtoupper($method);
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            $params = self::matchSegments($route['segments'], $parts);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedOtherMethod = true;
                continue;
            }

            return ['handler' => $route['handler'], 'params' => $params, 'auth' => $route['auth']];
        }

        // A declared path reached with the wrong verb is a 405, not a 404 —
        // the difference is what tells a client "you used GET where PATCH
        // belongs" instead of "this endpoint does not exist".
        if ($pathMatchedOtherMethod) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'That method is not allowed on this endpoint.');
        }

        return null;
    }

    /**
     * @param array<int, string> $pattern
     * @param array<int, string> $parts
     * @return array<string, string>|null
     */
    private static function matchSegments(array $pattern, array $parts): ?array
    {
        if (count($pattern) !== count($parts)) {
            return null;
        }

        $params = [];
        foreach ($pattern as $index => $segment) {
            if (strlen($segment) > 1 && $segment[0] === '{' && $segment[-1] === '}') {
                $params[substr($segment, 1, -1)] = $parts[$index];
                continue;
            }
            if ($segment !== $parts[$index]) {
                return null;
            }
        }

        return $params;
    }

    /** @return array<int, string> */
    private static function split(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
    }
}
