<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Env;

/**
 * Where another AICOUNTLY product's API lives.
 *
 * Mirrors `App\Services\ProductApiResolver` in aicountly/pulse-aicountly, which
 * is the settled convention across the suite: an explicit override if one is
 * set, otherwise derived from the host this request arrived on. A sandbox
 * deployment talks to sandbox siblings and a production one to production
 * siblings, with nothing to configure — which matters because the alternative
 * is four URLs in every environment's .env that its own hostname already
 * implies, and four chances to point production at sandbox.
 *
 * The sandbox test is deliberately identical to `isSandboxHost()` in
 * `web/src/auth/hostnames.ts`. Both came from the same place; if one changes,
 * the other has to.
 */
final class SiblingApi
{
    /** Products this API may address. An unknown key is a programming error, not input. */
    private const KNOWN = [
        'drive', 'calendar', 'contacts', 'connect', 'pulse',
        'manage', 'books', 'docs', 'insights',
    ];

    /**
     * Origin for a sibling product, with no trailing slash.
     *
     * Resolution order:
     *   1. `{PRODUCT}_API_URL` — the explicit override, and what a deployment
     *      sets when a product is somewhere unusual.
     *   2. The host this request arrived on.
     */
    public static function origin(string $product, ?string $host = null): string
    {
        $product = strtolower(trim($product));
        if (!in_array($product, self::KNOWN, true)) {
            throw new \InvalidArgumentException('Unknown AICOUNTLY product: ' . $product);
        }

        $override = trim(Env::get(strtoupper($product) . '_API_URL'));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $zone = self::isSandbox($host ?? self::currentHost()) ? '.gh.aicountly.com' : '.aicountly.com';

        // Two products are not reachable at their own name. `pulse` still
        // answers on the host it was named `buddy` under in sandbox, and Drive
        // is served by the `docs` product. Getting either wrong produces a
        // connection that simply never resolves.
        $subdomain = match ($product) {
            'pulse' => $zone === '.aicountly.com' ? 'pulse' : 'buddy',
            'drive' => 'drive',
            default => $product,
        };

        return 'https://' . $subdomain . $zone;
    }

    /**
     * Is this a sandbox or local host?
     *
     * Anything unrecognised is treated as sandbox rather than production: a
     * misconfigured host that quietly talks to live company data is a worse
     * failure than one that talks to sandbox and looks empty.
     */
    public static function isSandbox(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }
        // Strip a port before matching, or "localhost:8000" fails every test.
        $host = explode(':', $host)[0];

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_starts_with($host, '127.')) {
            return true;
        }
        if (preg_match('/^[a-z0-9-]+\.gh\.aicountly\.com$/', $host) === 1) {
            return true;
        }
        if (preg_match('/^gh-[a-z0-9-]+\.aicountly\.com$/', $host) === 1) {
            return true;
        }

        return preg_match('/^[a-z0-9-]+\.aicountly\.com$/', $host) !== 1;
    }

    private static function currentHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        return is_string($host) ? $host : '';
    }
}
