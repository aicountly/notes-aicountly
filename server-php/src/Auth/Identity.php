<?php

declare(strict_types=1);

namespace Aicountly\Api\Auth;

/**
 * Who is making this request, as the portal describes them.
 *
 * `tenantId` is the company context. It is nullable on purpose: a personal
 * note has no company, and conflating "no tenant" with "some default tenant"
 * is how one user's private notes end up visible to an organisation.
 */
final class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $tenantId = null,
        public readonly string $displayName = '',
        public readonly string $email = '',
    ) {
    }

    /** The pair every tenant-scoped query binds. */
    public function scope(): array
    {
        return ['user_id' => $this->userId, 'tenant_id' => $this->tenantId];
    }

    public function isSameTenant(?string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'display_name' => $this->displayName,
            'email' => $this->email,
        ];
    }
}
