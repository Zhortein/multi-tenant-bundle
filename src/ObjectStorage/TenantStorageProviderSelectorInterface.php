<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantStorageProviderSelectorInterface
{
    public function selectForNewObject(TenantInterface $tenant): string;
}
