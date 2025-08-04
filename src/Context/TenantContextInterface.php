<?php

namespace Zhortein\MultiTenantBundle\Context;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantContextInterface
{
    public function getTenant(): ?TenantInterface;

    public function hasTenant(): bool;

    public function setTenant(TenantInterface $tenant): void;

    public function clear(): void;
}
