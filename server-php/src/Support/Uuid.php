<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * RFC 4122 version 4 identifiers.
 *
 * Generated in PHP rather than by the database so a note has its id before the
 * INSERT: the client creates a note optimistically, offline if need be, and the
 * id it used locally is the id the server stores. That is what makes the
 * offline queue replay-safe.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
