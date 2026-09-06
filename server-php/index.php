<?php

declare(strict_types=1);

/**
 * Notes API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the React app on
 * both notes.aicountly.com and notes.gh.aicountly.com.
 *
 * Three kinds of route live here:
 *
 *   GET  /api/health          liveness + which environment answered  (public)
 *   GET  /api/config          feature flags and limits               (public)
 *   POST /api/global/{path}   allow-listed relay to the portal auth API
 *   *    /api/…               the product API, behind a Bearer ses_key
 *
 * The product API is declared in src/Routes.php; everything below is the
 * plumbing that gets a request to it and an answer back.
 */

namespace Aicountly\Api;

require __DIR__ . '/src/Autoloader.php';

Autoloader::register(__DIR__ . '/src');
Env::load(__DIR__ . '/.env');

use Aicountly\Api\Auth\SessionGuard;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Http\Router;
use Aicountly\Api\Support\Logger;

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * The Authorization header, wherever this server happens to expose it.
 *
 * Under CGI/FastCGI Apache does not pass it to PHP unless it is copied
 * explicitly, and after an internal rewrite it arrives only under the
 * REDIRECT_ prefix. Reading just one of these is why an otherwise correct
 * deployment answers 401 to every sign-in.
 */
function authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
                break;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function bearer_token(): string
{
    $header = authorization_header();
    if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * Collapse a routed path to the exact form RELAYED_PATHS is written in.
 *
 * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
 * segment past the allowlist; exact matching does the rest.
 */
function normalise_path(string $path): string
{
    $decoded = str_replace('\\', '/', rawurldecode($path));
    $segments = array_values(array_filter(explode('/', $decoded), static fn ($s) => $s !== ''));

    return strtolower(implode('/', $segments));
}

/**
 * CORS for local development only.
 *
 * In both deployed environments the app and this API share an origin, so no
 * CORS headers are needed or sent. CORS_ALLOWED_ORIGINS in the server .env is
 * what lets `npm run dev` on localhost talk to a deployed API.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS'))));
    if (!in_array($origin, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-Id, If-Match');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: X-Request-Id');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

/**
 * Liveness, plus the few operational numbers worth having without a login.
 *
 * Deliberately contains no note content and no counts of anyone's notes — this
 * endpoint is public. What it does report is whether the database answers and
 * whether the background worker is running, because the failure mode those
 * cause is silent: uploads keep succeeding and attachments simply never finish
 * processing, which looks like nothing at all until someone asks why a scan has
 * no text.
 *
 * @return array<string, mixed>
 */
function health_report(): array
{
    $report = [
        'status' => 'ok',
        'app' => 'Notes',
        'env' => Env::get('APP_ENV', 'unknown'),
        'time' => gmdate('c'),
        'database' => 'unknown',
    ];

    try {
        $row = Database\Connection::selectOne(
            "SELECT
                (SELECT count(*) FROM note_processing_jobs WHERE status = 'queued')     AS queued,
                (SELECT count(*) FROM note_processing_jobs WHERE status = 'processing') AS processing,
                (SELECT count(*) FROM note_processing_jobs
                  WHERE status = 'failed' AND updated_at > now() - interval '24 hours') AS failed_24h,
                (SELECT count(*) FROM note_processing_jobs
                  WHERE status = 'queued' AND available_at < now() - interval '30 minutes') AS overdue,
                (SELECT max(finished_at) FROM note_processing_jobs WHERE status = 'completed') AS last_completed",
        );

        $report['database'] = 'ok';
        $report['jobs'] = [
            'queued' => (int) ($row['queued'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'failed_24h' => (int) ($row['failed_24h'] ?? 0),
            'last_completed_at' => $row['last_completed'] ?? null,
        ];

        // A job that has been due for half an hour means nothing is running the
        // worker — the cron entry is the thing to check. See docs/DEPLOYMENT.md.
        if ((int) ($row['overdue'] ?? 0) > 0) {
            $report['status'] = 'degraded';
            $report['worker'] = 'stalled';
        }
    } catch (\Throwable) {
        // A health check must answer even when the thing it is checking is down;
        // reporting "degraded" is the whole point of the endpoint.
        $report['status'] = 'degraded';
        $report['database'] = 'unavailable';
    }

    return $report;
}

/** Relay one allow-listed portal auth call. Never reaches the router. */
function relay_to_portal(string $method, string $path): void
{
    $portalPath = substr($path, strlen('global/'));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        Response::error(ApiException::notFound('That path'))->send();
        exit;
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        Response::error(ApiException::upstream('portal', 'Auth service unavailable — please retry.'))->send();
        exit;
    }

    // The portal's own body is passed through untouched: the SPA's auth code
    // reads the portal's shape here, not this API's envelope.
    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = normalise_path($uri);

// A client-supplied request id makes one user's report traceable across the
// SPA and the server log. It is echoed back, never trusted for anything else.
$incomingRequestId = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incomingRequestId) === 1) {
    Logger::setRequestId($incomingRequestId);
}

if ($path === '' || $path === 'health') {
    Response::ok(health_report())->send();
    exit;
}

if ($path === 'config') {
    Routes::publicConfig()->send();
    exit;
}

if (strpos($path, 'global/') === 0) {
    relay_to_portal($method, $path);
}

// `/session` predates the product API and keeps its original shape, because the
// SPA's auth bootstrap already reads it. Everything after this point is the
// product API and uses the {success, data} envelope.
if ($path === 'session') {
    try {
        $identity = SessionGuard::authenticate(bearer_token());
        Response::ok([
            'authenticated' => true,
            'uuid' => $identity->userId,
            'tenant_id' => $identity->tenantId,
            'display_name' => $identity->displayName,
            'email' => $identity->email,
        ])->send();
    } catch (ApiException $e) {
        Response::error($e)->send();
    }
    exit;
}

$router = new Router();
Routes::register($router);

try {
    $matched = $router->match($method, $path);
    if ($matched === null) {
        throw ApiException::notFound('That endpoint');
    }

    $request = Request::fromGlobals($path, $method, bearer_token());
    $request->routeParams = $matched['params'];

    $identity = $matched['auth']
        ? SessionGuard::authenticate($request->bearerToken)
        : new Auth\Identity('');

    /** @var Response $response */
    $response = ($matched['handler'])($request, $identity);
    $response->send();
} catch (ApiException $e) {
    Response::error($e)->send();
} catch (\Throwable $e) {
    // The only place an unexpected throwable is turned into a response. The
    // message and stack trace stay in the log: a production client gets a code
    // and a request id to quote, never the internals of a failed query.
    Logger::error('request.unhandled', [
        'exception' => get_debug_type($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'path' => $path,
        'method' => $method,
    ]);

    $debug = Env::get('APP_DEBUG') === 'true' && Env::get('APP_ENV') !== 'production';
    Response::error(new ApiException(
        500,
        'INTERNAL_ERROR',
        $debug
            ? get_debug_type($e) . ': ' . $e->getMessage()
            : 'Something went wrong on our side. Please try again.',
        $debug ? ['file' => $e->getFile(), 'line' => $e->getLine()] : [],
    ))->send();
}
