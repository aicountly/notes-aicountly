<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Env;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * Attachment bytes on the server's own disk.
 *
 * The fallback for a deployment without AICOUNTLY Drive, and the store the test
 * suite uses. Two properties make it safe enough to be a fallback rather than a
 * liability:
 *
 *   - **The directory is not servable.** On cPanel the API lives *inside* the
 *     document root, so `<docroot>/api/storage/…` would otherwise be fetchable
 *     over HTTP by anyone who learned a key. The store writes a deny-all
 *     `.htaccess` the first time it creates its root, and the default root can
 *     be moved outside the document root with NOTES_STORAGE_PATH.
 *   - **Keys are allocated, never derived.** A key is 16 random bytes under a
 *     year/month prefix, and `pathFor()` re-validates that shape on every read,
 *     so a storage_key that somehow acquired `../` cannot address a file
 *     outside the root.
 */
final class LocalObjectStore implements ObjectStore
{
    /**
     * Apache 2.4 uses `Require`; 2.2 uses `Order`/`Deny`. Both are written
     * because a cPanel account's Apache version is not something this code can
     * check, and the wrong one alone would silently deny nothing.
     */
    private const DENY_ALL = <<<'HTACCESS'
    # Attachment bytes. Nothing here is ever served over HTTP: downloads go
    # through GET /api/notes/{id}/attachments/{attachmentId}/content, which
    # re-checks the caller's permission on the note first.
    <IfModule mod_authz_core.c>
      Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
      Order deny,allow
      Deny from all
    </IfModule>
    HTACCESS;

    /** `2026/09/<32 hex>` — the only shape this store will address. */
    private const KEY_PATTERN = '#^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}$#';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? self::defaultRoot(), '/');
    }

    public static function defaultRoot(): string
    {
        $configured = trim(Env::get('NOTES_STORAGE_PATH'));

        return $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__, 2) . '/storage';
    }

    public function name(): string
    {
        return 'local';
    }

    public function allocateKey(): string
    {
        return gmdate('Y/m') . '/' . bin2hex(random_bytes(16));
    }

    /**
     * Write the bytes and hand back the key they went to — the same key, always.
     *
     * The disk has no catalogue, so `$filename` is ignored here on purpose: a
     * name taken from a user must never reach a path. {@see DriveObjectStore} is
     * the implementation for which the return value differs from the argument.
     */
    public function put(string $key, string $bytes, string $mimeType, string $filename = ''): string
    {
        // The disk stores bytes; the type is the database's column, and the
        // name is the database's column too.
        unset($mimeType, $filename);

        $path = $this->pathFor($key);
        $this->ensureDirectory(dirname($path));

        // Write-then-rename, so a download can never observe a half-written
        // file: rename() is atomic within a filesystem.
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.part';
        if (file_put_contents($temporary, $bytes, LOCK_EX) === false) {
            Logger::error('storage.write_failed', ['store' => 'local']);
            throw new ApiException(500, 'STORAGE_WRITE_FAILED', 'The file could not be saved.');
        }
        @chmod($temporary, 0600);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            Logger::error('storage.rename_failed', ['store' => 'local']);
            throw new ApiException(500, 'STORAGE_WRITE_FAILED', 'The file could not be saved.');
        }

        return $key;
    }

    public function get(string $key): string
    {
        $path = $this->pathFor($key);
        $bytes = is_readable($path) ? file_get_contents($path) : false;

        if ($bytes === false) {
            throw ApiException::notFound('That file');
        }

        return $bytes;
    }

    public function stream(string $key): void
    {
        $path = $this->pathFor($key);
        if (!is_readable($path) || readfile($path) === false) {
            throw ApiException::notFound('That file');
        }
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        return is_file($path) && @unlink($path);
    }

    public function exists(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    public function signedUrl(string $key, int $ttlSeconds): ?string
    {
        unset($key, $ttlSeconds);

        // There is deliberately no URL: this directory is denied to Apache, so
        // pretending to sign one would produce a link that 403s.
        return null;
    }

    public function root(): string
    {
        return $this->root;
    }

    private function pathFor(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            // Not a validation error the user can fix: a key of this shape can
            // only come from a corrupted row or a traversal attempt.
            Logger::warn('storage.rejected_key', ['store' => 'local', 'length' => strlen($key)]);
            throw ApiException::notFound('That file');
        }

        return $this->root . '/' . $key;
    }

    /** Create the tree on first use, and lock the root down as it appears. */
    private function ensureDirectory(string $directory): void
    {
        $rootIsNew = !is_dir($this->root);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            Logger::error('storage.mkdir_failed', ['store' => 'local']);
            throw new ApiException(500, 'STORAGE_WRITE_FAILED', 'The file could not be saved.');
        }

        $htaccess = $this->root . '/.htaccess';
        if ($rootIsNew || !is_file($htaccess)) {
            // Best effort: a store on a server without Apache still works, and
            // a root outside the document root does not need this at all.
            @file_put_contents($htaccess, self::DENY_ALL . "\n");
        }
    }
}
