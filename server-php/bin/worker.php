<?php

declare(strict_types=1);

/**
 * Background worker.
 *
 *   php bin/worker.php                     # housekeeping, then a bounded batch
 *   php bin/worker.php --once              # exactly one job (useful when debugging)
 *   php bin/worker.php --max=50            # at most 50 jobs this run
 *   php bin/worker.php --timeout=120       # stop claiming after 120 seconds
 *
 * It runs a batch and exits, which is what makes it a cron command rather than
 * a daemon — cPanel has no supervisor to restart a long-lived process, and a
 * process that never exits is one that quietly dies on a Tuesday and takes
 * every thumbnail with it.
 *
 * Suggested crontab (every minute; the defaults finish inside the slot):
 *
 *   * * * * * /usr/local/bin/php /home/<user>/public_html/api/bin/worker.php >/dev/null 2>&1
 *
 * Overlapping runs are safe: jobs are claimed with FOR UPDATE SKIP LOCKED, so a
 * second worker takes different work rather than the same work twice.
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    // Nothing here is reachable over HTTP. The API folder is inside the
    // document root on cPanel, so this file is fetchable and must refuse.
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Autoloader.php';
Autoloader::register(__DIR__ . '/../src');
Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\Jobs\Worker;

/** @param array<int, string> $argv */
function option(array $argv, string $name, int $default, int $min, int $max): int
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            $value = (int) substr($argument, strlen($name) + 3);

            return max($min, min($max, $value));
        }
    }

    return $default;
}

$arguments = array_slice($argv, 1);

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo "Usage: php bin/worker.php [--once] [--max=N] [--timeout=SECONDS]\n";
    exit(0);
}

$once = in_array('--once', $arguments, true);
$max = $once ? 1 : option($arguments, 'max', Worker::DEFAULT_BATCH, 1, 500);
$timeout = option($arguments, 'timeout', Worker::DEFAULT_TIMEOUT_SECONDS, 1, 3600);

try {
    $worker = new Worker();

    // Housekeeping first: it is small, it must not be starved by a busy queue,
    // and it is the part with a deadline attached (a trash retention promise).
    $summary = $worker->maintenance() + $worker->run($max, $timeout);

    echo json_encode(['ok' => true] + $summary, JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (\Throwable $e) {
    // Cron mails stderr. The class and message are enough to act on; nothing
    // from a note ever reaches this line.
    fwrite(STDERR, 'Worker failed: ' . get_debug_type($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
