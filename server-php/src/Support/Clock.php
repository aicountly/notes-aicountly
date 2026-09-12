<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * The single source of "now".
 *
 * Injectable so tests can pin time instead of sleeping through it.
 */
final class Clock
{
    private static ?int $frozenAt = null;

    public static function now(): \DateTimeImmutable
    {
        $timestamp = self::$frozenAt;

        return $timestamp === null
            ? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            : (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function iso(): string
    {
        return self::now()->format(\DateTimeInterface::RFC3339);
    }

    /** Test seam. Production never calls this. */
    public static function freeze(?int $unixTimestamp): void
    {
        self::$frozenAt = $unixTimestamp;
    }
}
