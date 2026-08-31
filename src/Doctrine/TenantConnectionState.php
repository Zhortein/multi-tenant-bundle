<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final readonly class TenantConnectionState
{
    private function __construct(
        public TenantConnectionMode $mode,
        public ?TenantInterface $tenant = null,
    ) {
        if (TenantConnectionMode::TENANT === $mode && null === $tenant) {
            throw new \InvalidArgumentException('A tenant connection state requires a tenant.');
        }
        if (TenantConnectionMode::TENANT !== $mode && null !== $tenant) {
            throw new \InvalidArgumentException('Only a tenant connection state can carry a tenant.');
        }
    }

    public static function tenant(TenantInterface $tenant): self
    {
        return new self(TenantConnectionMode::TENANT, $tenant);
    }

    public static function global(): self
    {
        return new self(TenantConnectionMode::GLOBAL);
    }

    public static function none(): self
    {
        return new self(TenantConnectionMode::NONE);
    }
}
