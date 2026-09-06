<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php            # everything
 *   php tests/run.php Notes      # only cases whose class name contains "Notes"
 *
 * Requires a PostgreSQL database it may TRUNCATE. Point it at one with
 * TEST_DB_NAME in server-php/.env, or it uses DB_NAME with `_test` appended.
 */

namespace Aicountly\Api\Tests;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Autoloader.php';
\Aicountly\Api\Autoloader::register(__DIR__ . '/../src');
\Aicountly\Api\Env::load(__DIR__ . '/../.env');

require __DIR__ . '/TestCase.php';
require __DIR__ . '/ApiClient.php';
require __DIR__ . '/Support.php';

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Database\Migrator;

// Refuse to run against anything that is not clearly a test database: this
// suite truncates every table it knows about.
$database = \Aicountly\Api\Env::get('TEST_DB_NAME', \Aicountly\Api\Env::get('DB_NAME') . '_test');
if (!str_contains($database, 'test')) {
    fwrite(STDERR, "Refusing to run: TEST_DB_NAME ('{$database}') does not look like a test database.\n");
    exit(1);
}
putenv('DB_NAME=' . $database);

/**
 * Give this run its own object store.
 *
 * The database is not the only shared state a test touches: LocalObjectStore
 * writes real files, and without this every run — including two running at once
 * — shares `server-php/storage`. That is enough to make the purge tests flaky,
 * because one run's cleanup deletes another's fixtures, and it leaves stray
 * objects in the working tree afterwards.
 *
 * Keyed on the database name so a parallel run against its own database gets
 * its own directory for free.
 */
$storage = sys_get_temp_dir() . '/notes-test-storage-' . preg_replace('/[^a-z0-9_]/i', '', $database);
putenv('NOTES_STORAGE_PATH=' . $storage);

// A leftover store from a previous run would make "the object is gone"
// assertions pass for the wrong reason.
if (is_dir($storage)) {
    $entries = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
}

try {
    (new Migrator(__DIR__ . '/../migrations'))->up();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not prepare the test schema: ' . $e->getMessage() . "\n");
    exit(1);
}

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/Cases/*.php') ?: [];
sort($files);

$totalAssertions = 0;
$failures = [];
$caseCount = 0;

foreach ($files as $file) {
    require_once $file;
}

foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }
    if ($filter !== '' && !str_contains($class, $filter)) {
        continue;
    }

    $shortName = substr($class, strrpos($class, '\\') + 1);

    foreach ((new $class())->testMethods() as $method) {
        /** @var TestCase $case */
        $case = new $class();
        $caseCount++;

        try {
            Support::reset();
            $case->setUp();
            $case->{$method}();
        } catch (\Throwable $e) {
            $case->failures[] = sprintf(
                'threw %s: %s (%s:%d)',
                get_debug_type($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            );
        }

        $totalAssertions += $case->assertions;

        if ($case->failures === []) {
            echo "\033[32m.\033[0m";
            continue;
        }

        echo "\033[31mF\033[0m";
        foreach ($case->failures as $failure) {
            $failures[] = $shortName . '::' . $method . ' — ' . $failure;
        }
    }
}

echo "\n\n";

if ($failures !== []) {
    echo "\033[31mFAILURES\033[0m\n\n";
    foreach ($failures as $index => $failure) {
        echo sprintf("%3d) %s\n", $index + 1, $failure);
    }
    echo "\n";
}

printf(
    "%s%d tests, %d assertions, %d failures\033[0m\n",
    $failures === [] ? "\033[32m" : "\033[31m",
    $caseCount,
    $totalAssertions,
    count($failures),
);

exit($failures === [] ? 0 : 1);
