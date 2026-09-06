<?php

declare(strict_types=1);

namespace Aicountly\Api\Http;

/**
 * The company, financial year and branch this request is about.
 *
 * Across AICOUNTLY these travel as the query parameters `cmp_id`, `fy_id` and
 * `bo_id`. Pulse reads exactly those in `BaseController::companyContext()`, and
 * every product that scopes data by company expects them on an inbound call.
 *
 * Notes itself does **not** use them. Its own scope is the `tenant_id` the
 * portal returns from `validatesession` (see {@see \Aicountly\Api\Auth\Identity}),
 * which is a company UUID rather than the numeric `cmp_id` the older products
 * key on, and the two are not interchangeable. So nothing here is used to decide
 * who may read a note — mistaking a client-supplied query parameter for an
 * authorisation input is exactly the bug this class must not introduce.
 *
 * What it is for is the other direction: when Notes calls a sibling, the sibling
 * needs to be told which company is being asked about, and the only honest
 * source for that is what the caller sent. A Notes→Contacts call that drops
 * `cmp_id` is the classic "works for me, empty for them" failure — the sibling
 * answers for its own default company, or for none.
 *
 * Request-scoped static state, in the same shape as {@see \Aicountly\Api\Support\Logger}'s
 * request id and {@see \Aicountly\Api\Auth\SessionGuard}'s identity: one PHP
 * process serves one request, and threading three parameters through five layers
 * of service to reach one cURL call buys nothing.
 */
final class CompanyContext
{
    /** @var array<string, string> */
    private static array $params = [];

    /**
     * Record the context this request arrived with.
     *
     * Values are accepted only in the shape the suite uses — digits, and a
     * short one at that — because they are pasted straight into an outbound
     * query string. Anything else is dropped rather than forwarded: a sibling
     * asked about company `1 OR 1=1` should never have been asked at all.
     *
     * @param array<string, string|int|float|bool|null> $query
     */
    public static function capture(array $query): void
    {
        self::$params = [];

        foreach (['cmp_id', 'fy_id', 'bo_id'] as $key) {
            $value = $query[$key] ?? null;
            if ($value === null || !is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && preg_match('/^[0-9]{1,20}$/', $value) === 1) {
                self::$params[$key] = $value;
            }
        }
    }

    /**
     * The parameters to put on an outbound call to a sibling product.
     *
     * Empty when the caller sent none, which is the common case today: Notes'
     * own frontend is personal-first and does not carry a company selector. An
     * empty array is the honest answer — better than a company id this server
     * guessed at.
     *
     * @return array<string, string>
     */
    public static function params(): array
    {
        return self::$params;
    }

    /** Test seam, and what a worker process starts from: no request, no context. */
    public static function clear(): void
    {
        self::$params = [];
    }
}
