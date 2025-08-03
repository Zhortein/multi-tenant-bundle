<?php

namespace Zhortein\MultiTenantBundle\Context;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Holds the tenant context for the current request lifecycle.
 */
final class TenantContext
{
    private ?TenantInterface $tenant = null;

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}