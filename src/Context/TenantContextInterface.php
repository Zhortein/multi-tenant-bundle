<?php

namespace Zhortein\MultiTenantBundle\Context;

use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantContextInterface extends ResetInterface
{
    public function getTenant(): ?TenantInterface;

    public function hasTenant(): bool;

    public function setTenant(TenantInterface $tenant): void;

    public function clear(): void;

    /**
     * Invalidates the logical context first, then resets every synchronized
     * tenant resource to its fail-closed NONE state.
     */
    public function reset(): void;
}
