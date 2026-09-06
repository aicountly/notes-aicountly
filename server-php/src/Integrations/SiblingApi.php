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
 * ## A product code is not a hostname
 *
 * This is the trap the whole class exists to close. Three products in the suite
 * answer on a host that is not spelled like their code:
 *
 * | Product | `product_code` | Host                     |
 * |---------|----------------|--------------------------|
 * | Drive   | `docs`         | `drive.aicountly.com`    |
 * | Connect | `chat`         | `connect.aicountly.com`  |
 * | Pulse   | `buddy`        | `pulse.aicountly.com`    |
 *
 * (See §10 of Drive's AICOUNTLY_DRIVE_STORAGE_ARCHITECTURE.md, which is the
 * registry of record.) The code is what goes in a request body — Drive's
 * `product_code`, an object key segment, a `documents` row — and it is frozen
 * forever. The host is where the socket opens. Treating one as the other gives
 * `https://docs.aicountly.com`, a name that resolves nowhere, and the symptom
 * is a connection error rather than anything that points at the cause.
 *
 * So {@see origin()} takes **either** spelling and answers with the host, and
 * {@see productCode()} answers with the code. Neither is derived from the other
 * by string manipulation.
 *
 * ## Sandbox detection
 *
 * The zone test is the one Pulse's `ProductApiResolver::isSandboxHost()` uses,
 * which is in turn the one `web/src/auth/hostnames.ts` ships — both came from
 * books-react-app. It differs from those two deliberately in one place, and
 * only one: **an unrecognised host resolves to sandbox here, not production.**
 * The frontend's copy answers "is this build a sandbox build", where guessing
 * wrong costs a redirect. This copy decides which company's live data a
 * server-to-server call reaches, so it fails towards the empty environment.
 */
final class SiblingApi
{
    /**
     * Products this API may address: `product_code` => host label per zone.
     *
     * An unknown key is a programming error, not input — no request can name a
     * product, so reaching the exception means a caller is wrong.
     *
     * @var array<string, array{prod: string, sandbox: string}>
     */
    private const PRODUCTS = [
        // Drive is served by the `docs` product; its host has always been `drive`.
        'docs' => ['prod' => 'drive', 'sandbox' => 'drive'],
        // Connect was renamed from Chat. The code stayed `chat`, the host is `connect`.
        'chat' => ['prod' => 'connect', 'sandbox' => 'connect'],
        // Pulse was renamed from Buddy in production only; sandbox still serves
        // the old name, so `pulse.gh.aicountly.com` resolves nowhere.
        'buddy' => ['prod' => 'pulse', 'sandbox' => 'buddy'],
        'calendar' => ['prod' => 'calendar', 'sandbox' => 'calendar'],
        'contacts' => ['prod' => 'contacts', 'sandbox' => 'contacts'],
        'manage' => ['prod' => 'manage', 'sandbox' => 'manage'],
        'books' => ['prod' => 'books', 'sandbox' => 'books'],
        'insights' => ['prod' => 'insights', 'sandbox' => 'insights'],
    ];

    /**
     * Names callers use for a product, mapped to its `product_code`.
     *
     * Written out rather than inferred: `drive` and `docs` are the same product
     * under two names, and a caller that says either must reach the same host.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'drive' => 'docs',
        'connect' => 'chat',
        'pulse' => 'buddy',
    ];

    /** The path every AICOUNTLY product mounts its API under. */
    private const API_PREFIX = '/api';

    /**
     * The `product_code` for a name a caller used.
     *
     * This is the value that belongs in a request body — Drive's
     * `product_code` field, for one — and never in a URL host.
     */
    public static function productCode(string $product): string
    {
        $product = strtolower(trim($product));
        $code = self::ALIASES[$product] ?? $product;

        if (!isset(self::PRODUCTS[$code])) {
            throw new \InvalidArgumentException('Unknown AICOUNTLY product: ' . $product);
        }

        return $code;
    }

    /**
     * Scheme and host for a sibling product, with no path and no trailing slash.
     *
     * The same shape `ProductApiResolver::resolve()` returns in Pulse, so a
     * caller appends `/api/...` exactly as Pulse's proxy does.
     *
     * Resolution order:
     *   1. `{PRODUCT}_API_ORIGIN` — the explicit override, and the name the rest
     *      of the suite uses. Keyed on the product_code (`DOCS_API_ORIGIN`), with
     *      the host spelling (`DRIVE_API_ORIGIN`) accepted too, because the
     *      person editing a .env is looking at a hostname.
     *   2. The host this request arrived on.
     */
    public static function origin(string $product, ?string $host = null): string
    {
        $code = self::productCode($product);

        $override = self::override($code, '_API_ORIGIN');
        if ($override !== '') {
            return $override;
        }

        $sandbox = self::isSandbox($host ?? self::currentHost());
        $label = self::PRODUCTS[$code][$sandbox ? 'sandbox' : 'prod'];
        $zone = $sandbox ? '.gh.aicountly.com' : '.aicountly.com';

        return 'https://' . $label . $zone;
    }

    /**
     * The base a sibling's API paths hang off — origin plus `/api`.
     *
     * Every product in the suite mounts its API at `/api`, which is why Pulse's
     * proxy builds `{origin}/api/{path}` rather than storing the prefix per
     * product. Callers concatenate a leading-slash path onto this.
     *
     * `{PRODUCT}_API_URL` is honoured here for deployments that predate
     * `_API_ORIGIN`. It is read **verbatim**, because that variable always named
     * a full API base (`https://drive.aicountly.com/api`) rather than an origin,
     * and appending `/api` to it would produce `/api/api`. New deployments should
     * set `_API_ORIGIN`, or nothing at all.
     */
    public static function apiBase(string $product, ?string $host = null): string
    {
        $code = self::productCode($product);

        $legacy = self::override($code, '_API_URL');
        if ($legacy !== '' && self::override($code, '_API_ORIGIN') === '') {
            return $legacy;
        }

        return self::origin($product, $host) . self::API_PREFIX;
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

    // -----------------------------------------------------------------------

    /**
     * An override for this product under `$suffix`, or ''.
     *
     * Both spellings are accepted — `DOCS_API_ORIGIN` and `DRIVE_API_ORIGIN` —
     * with the product_code winning, so a .env that sets both is not ambiguous.
     *
     * `buddy`/`pulse` is the one product where this must NOT reach for a bare
     * `PULSE_API_URL`: in this repository that variable names the **model
     * gateway** {@see \Aicountly\Api\Domain\Ai\HttpPulseProvider} posts
     * completions to, which is a different service from the Pulse product's own
     * API. Pointing a sibling call at it would send Notes' requests to whatever
     * gateway the AI feature is configured against. See docs/PULSE_INTEGRATION.md.
     */
    private static function override(string $code, string $suffix): string
    {
        $names = [strtoupper($code) . $suffix];
        foreach (self::ALIASES as $alias => $aliased) {
            if ($aliased === $code) {
                $names[] = strtoupper($alias) . $suffix;
            }
        }
        if ($code === 'buddy' && $suffix === '_API_URL') {
            // PULSE_API_URL is the model gateway, not the Pulse product.
            $names = ['BUDDY_API_URL'];
        }

        foreach ($names as $name) {
            $value = trim(Env::get($name));
            if ($value !== '') {
                return rtrim($value, '/');
            }
        }

        return '';
    }

    private static function currentHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        return is_string($host) ? $host : '';
    }
}
