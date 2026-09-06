<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * PSR-4 autoloader for `Aicountly\Api\` → `server-php/src/`.
 *
 * Hand-written rather than Composer's: this API ships to cPanel by rsync with
 * no build step and no `composer install` on the server, so a vendor directory
 * would have to be committed to the repository to exist at all. The trade is a
 * dozen lines here against that, and the deploy workflow's `php -l` sweep keeps
 * covering every file.
 */
final class Autoloader
{
    private const PREFIX = 'Aicountly\\Api\\';

    public static function register(string $baseDir): void
    {
        $baseDir = rtrim($baseDir, '/\\') . '/';

        spl_autoload_register(static function (string $class) use ($baseDir): void {
            if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
                return;
            }

            $relative = substr($class, strlen(self::PREFIX));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

            // realpath() collapses any traversal a class name could smuggle in,
            // and the prefix check confines the result to src/.
            $resolved = realpath($path);
            if ($resolved !== false && strncmp($resolved, realpath($baseDir) . '/', strlen(realpath($baseDir)) + 1) === 0) {
                require $resolved;
            }
        });
    }
}
