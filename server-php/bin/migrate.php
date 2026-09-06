<?php

declare(strict_types=1);

/**
 * Migration CLI.
 *
 *   php bin/migrate.php status
 *   php bin/migrate.php up
 *   php bin/migrate.php down [steps]
 *   php bin/migrate.php seed        # system templates only — never demo data
 *   php bin/migrate.php fresh       # DEV ONLY: down everything, then up
 *
 * cPanel has no deploy hook, so this is run once over SSH after a deploy that
 * adds a migration. See docs/DEPLOYMENT.md.
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Autoloader.php';
Autoloader::register(__DIR__ . '/../src');
Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Database\Migrator;
use Aicountly\Api\Domain\Templates\SystemTemplateSeeder;

$command = $argv[1] ?? 'status';
$migrator = new Migrator(__DIR__ . '/../migrations');

try {
    switch ($command) {
        case 'status':
            $applied = $migrator->applied();
            foreach ($migrator->available() as $migration) {
                $mark = in_array($migration['version'], $applied, true) ? '[x]' : '[ ]';
                echo $mark . ' ' . $migration['version'] . PHP_EOL;
            }
            break;

        case 'up':
            $results = $migrator->up();
            if ($results === []) {
                echo 'Nothing to migrate.' . PHP_EOL;
                break;
            }
            foreach ($results as $result) {
                echo sprintf(
                    '%-14s %s%s',
                    $result['status'],
                    $result['version'],
                    isset($result['error']) ? '  (' . $result['error'] . ')' : '',
                ) . PHP_EOL;
            }
            break;

        case 'down':
            foreach ($migrator->down((int) ($argv[2] ?? 1)) as $result) {
                echo $result['status'] . ' ' . $result['version'] . PHP_EOL;
            }
            break;

        case 'seed':
            $count = (new SystemTemplateSeeder())->run();
            echo 'System templates seeded: ' . $count . PHP_EOL;
            break;

        case 'fresh':
            if (Env::get('APP_ENV') === 'production') {
                fwrite(STDERR, 'Refusing to run `fresh` against a production environment.' . PHP_EOL);
                exit(1);
            }
            $migrator->down(count($migrator->applied()));
            foreach ($migrator->up() as $result) {
                echo $result['status'] . ' ' . $result['version'] . PHP_EOL;
            }
            echo 'System templates seeded: ' . (new SystemTemplateSeeder())->run() . PHP_EOL;
            break;

        default:
            fwrite(STDERR, "Unknown command `{$command}`. Try: status | up | down | seed | fresh" . PHP_EOL);
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
