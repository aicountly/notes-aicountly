<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests;

/**
 * Assertions, minimal.
 *
 * This project ships no Composer dependencies — the API is rsynced to cPanel
 * as-is, with no `composer install` on the server — so a test framework would
 * have to be vendored into the repository to exist. These few methods are the
 * whole cost of not doing that.
 */
abstract class TestCase
{
    public int $assertions = 0;

    /** @var array<int, string> */
    public array $failures = [];

    abstract public function name(): string;

    /** @return array<int, string> Method names to run. */
    public function testMethods(): array
    {
        return array_values(array_filter(
            get_class_methods($this),
            static fn (string $m) => str_starts_with($m, 'test'),
        ));
    }

    /** Fresh state before each test. */
    public function setUp(): void
    {
    }

    protected function pass(): void
    {
        $this->assertions++;
    }

    protected function fail(string $message): void
    {
        $this->assertions++;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $frame = $trace[2] ?? $trace[1] ?? [];
        $this->failures[] = sprintf(
            '%s (%s:%d)',
            $message,
            basename((string) ($frame['file'] ?? '?')),
            (int) ($frame['line'] ?? 0),
        );
    }

    protected function assertTrue(mixed $value, string $message = 'expected true'): void
    {
        $value === true ? $this->pass() : $this->fail($message . ' — got ' . var_export($value, true));
    }

    protected function assertFalse(mixed $value, string $message = 'expected false'): void
    {
        $value === false ? $this->pass() : $this->fail($message . ' — got ' . var_export($value, true));
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->pass();

            return;
        }
        $this->fail(sprintf(
            '%s expected %s, got %s',
            $message !== '' ? $message . ':' : 'assertSame:',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $expected !== $actual ? $this->pass() : $this->fail(($message ?: 'assertNotSame') . ': values are identical');
    }

    protected function assertNull(mixed $value, string $message = 'expected null'): void
    {
        $value === null ? $this->pass() : $this->fail($message . ' — got ' . var_export($value, true));
    }

    protected function assertNotNull(mixed $value, string $message = 'expected a value'): void
    {
        $value !== null ? $this->pass() : $this->fail($message);
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        count($actual) === $expected
            ? $this->pass()
            : $this->fail(sprintf('%s expected %d items, got %d', $message ?: 'assertCount:', $expected, count($actual)));
    }

    protected function assertContainsString(string $needle, string $haystack, string $message = ''): void
    {
        str_contains($haystack, $needle)
            ? $this->pass()
            : $this->fail(sprintf('%s "%s" not found in "%s"', $message ?: 'assertContainsString:', $needle, mb_substr($haystack, 0, 200)));
    }

    /**
     * Assert that running $work throws an ApiException with this error code.
     *
     * The code, not the message: the codes are the contract the frontend
     * branches on, so a test that only checked wording would pass while the
     * client broke.
     */
    protected function assertApiError(string $expectedCode, callable $work, string $message = ''): void
    {
        try {
            $work();
        } catch (\Aicountly\Api\Http\ApiException $e) {
            $e->errorCode === $expectedCode
                ? $this->pass()
                : $this->fail(sprintf(
                    '%s expected error %s, got %s (%s)',
                    $message ?: 'assertApiError:',
                    $expectedCode,
                    $e->errorCode,
                    $e->getMessage(),
                ));

            return;
        } catch (\Throwable $e) {
            $this->fail(sprintf('%s expected ApiException %s, got %s: %s', $message ?: 'assertApiError:', $expectedCode, get_debug_type($e), $e->getMessage()));

            return;
        }

        $this->fail(($message ?: 'assertApiError:') . ' expected ' . $expectedCode . ', nothing was thrown');
    }
}
