<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

/**
 * The four roles, ordered. `rank()` is what lets "the strongest grant wins" be
 * a comparison rather than a pile of nested conditionals.
 */
final class NoteRole
{
    public const OWNER = 'owner';
    public const EDITOR = 'editor';
    public const COMMENTER = 'commenter';
    public const VIEWER = 'viewer';

    public const ALL = [self::OWNER, self::EDITOR, self::COMMENTER, self::VIEWER];

    /** Roles that may be granted to someone else. Ownership is not transferable by sharing. */
    public const GRANTABLE = [self::EDITOR, self::COMMENTER, self::VIEWER];

    private const RANK = [
        self::OWNER => 4,
        self::EDITOR => 3,
        self::COMMENTER => 2,
        self::VIEWER => 1,
    ];

    public static function rank(?string $role): int
    {
        return self::RANK[$role ?? ''] ?? 0;
    }

    public static function atLeast(?string $role, string $required): bool
    {
        return self::rank($role) >= self::rank($required);
    }

    public static function isValid(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::ALL, true);
    }

    public static function isGrantable(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::GRANTABLE, true);
    }
}
