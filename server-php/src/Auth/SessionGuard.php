<?php

declare(strict_types=1);

namespace Aicountly\Api\Auth;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Env;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Portal;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Logger;

/**
 * Turns a Bearer ses_key into an {@see Identity}.
 *
 * The portal is the only authority on whether a session is live, but asking it
 * on every request would put my.aicountly.com in the critical path of every
 * autosave — a note editor makes a request every few seconds. So a successful
 * validation is cached briefly in `api_sessions`.
 *
 * Two properties keep that cache honest:
 *
 *   1. The key is stored as a SHA-256 hash. A dump of this table does not hand
 *      anyone a usable session.
 *   2. The TTL is short (default 60s) and never longer than the ses_key's own
 *      lifetime, so revoking a session at the portal takes effect in seconds
 *      rather than at the end of a long cache window.
 *
 * A portal outage denies access rather than granting it — the same rule the
 * existing `/api/session` endpoint already follows.
 */
final class SessionGuard
{
    private const DEFAULT_TTL_SECONDS = 60;

    /** Per-request memoisation: one request may authorise several times. */
    private static ?Identity $current = null;

    public static function authenticate(string $sesKey): Identity
    {
        if ($sesKey === '') {
            throw ApiException::unauthenticated('Missing bearer session key.');
        }

        if (self::$current instanceof Identity) {
            return self::$current;
        }

        $hash = hash('sha256', $sesKey);

        $cached = self::readCache($hash);
        if ($cached instanceof Identity) {
            return self::$current = $cached;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            throw ApiException::unauthenticated('Your session has expired. Sign in again.');
        }

        $identity = self::identityFromPortal($session);
        self::writeCache($hash, $identity);

        return self::$current = $identity;
    }

    /**
     * Map the portal's answer onto an Identity.
     *
     * The field names differ across AICOUNTLY products' portal responses, so
     * each is read from the aliases seen in practice. A response with no
     * recognisable user id is treated as a failed authentication rather than
     * as an anonymous user — the alternative is notes owned by the empty
     * string, shared by everyone who also fails to authenticate.
     *
     * @param array<string, mixed> $session
     */
    private static function identityFromPortal(array $session): Identity
    {
        $userId = self::firstString($session, ['uuid_aictly', 'uuid', 'user_uuid', 'user_id', 'id']);
        if ($userId === '') {
            Logger::error('auth.portal_response_without_user_id', ['keys' => array_keys($session)]);
            throw ApiException::unauthenticated('The sign-in service returned an unrecognised session.');
        }

        $tenantId = self::firstString($session, [
            'uuid_company', 'company_uuid', 'company_id', 'tenant_id', 'organisation_id', 'organization_id',
        ]);

        return new Identity(
            userId: $userId,
            tenantId: $tenantId === '' ? null : $tenantId,
            displayName: self::firstString($session, ['name', 'full_name', 'display_name', 'username']),
            email: self::firstString($session, ['email', 'email_id']),
        );
    }

    /** @param array<string, mixed> $data */
    private static function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
            // Some portal responses nest the user under `data` or `user`.
            foreach (['data', 'user', 'profile'] as $wrapper) {
                $nested = $data[$wrapper] ?? null;
                if (is_array($nested) && is_scalar($nested[$key] ?? null) && trim((string) $nested[$key]) !== '') {
                    return trim((string) $nested[$key]);
                }
            }
        }

        return '';
    }

    private static function ttl(): int
    {
        $configured = (int) Env::get('NOTES_SESSION_CACHE_SECONDS', (string) self::DEFAULT_TTL_SECONDS);

        // Never cache longer than the ses_key itself is meant to live, and
        // never so briefly that the cache stops being one.
        return max(0, min(300, $configured));
    }

    private static function readCache(string $hash): ?Identity
    {
        if (self::ttl() === 0) {
            return null;
        }

        try {
            $row = Connection::selectOne(
                'SELECT user_id, tenant_id, profile FROM api_sessions
                 WHERE ses_key_hash = :h AND expires_at > now()',
                ['h' => $hash],
            );
        } catch (\Throwable) {
            // A cache that cannot be read is not a reason to refuse a request;
            // the portal is asked instead.
            return null;
        }

        if ($row === null) {
            return null;
        }

        $profile = json_decode((string) ($row['profile'] ?? '{}'), true);
        $profile = is_array($profile) ? $profile : [];

        return new Identity(
            userId: (string) $row['user_id'],
            tenantId: $row['tenant_id'] === null ? null : (string) $row['tenant_id'],
            displayName: (string) ($profile['display_name'] ?? ''),
            email: (string) ($profile['email'] ?? ''),
        );
    }

    private static function writeCache(string $hash, Identity $identity): void
    {
        $ttl = self::ttl();
        if ($ttl === 0) {
            return;
        }

        try {
            Connection::execute(
                'INSERT INTO api_sessions (ses_key_hash, user_id, tenant_id, profile, expires_at)
                 VALUES (:h, :u, :t, :p::jsonb, now() + make_interval(secs => :ttl))
                 ON CONFLICT (ses_key_hash) DO UPDATE
                    SET user_id = EXCLUDED.user_id,
                        tenant_id = EXCLUDED.tenant_id,
                        profile = EXCLUDED.profile,
                        expires_at = EXCLUDED.expires_at',
                [
                    'h' => $hash,
                    'u' => $identity->userId,
                    't' => $identity->tenantId,
                    'p' => json_encode([
                        'display_name' => $identity->displayName,
                        'email' => $identity->email,
                    ]),
                    'ttl' => $ttl,
                ],
            );

            // Opportunistic sweep — this table has no other reaper, and it is
            // cheap because `api_sessions_expiry_idx` covers the predicate.
            if (random_int(1, 50) === 1) {
                Connection::execute('DELETE FROM api_sessions WHERE expires_at < now() - interval \'1 hour\'');
            }
        } catch (\Throwable $e) {
            Logger::warn('auth.session_cache_write_failed', ['error' => get_debug_type($e)]);
        }
    }

    /** Drop the cached session for a key — used on sign-out. */
    public static function invalidate(string $sesKey): void
    {
        self::$current = null;
        if ($sesKey === '') {
            return;
        }
        try {
            Connection::execute('DELETE FROM api_sessions WHERE ses_key_hash = :h', ['h' => hash('sha256', $sesKey)]);
        } catch (\Throwable) {
            /* best effort */
        }
    }

    /** Test seam. */
    public static function actAs(?Identity $identity): void
    {
        self::$current = $identity;
    }
}
