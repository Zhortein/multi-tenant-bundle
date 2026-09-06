<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantStorageNamespaceResolverInterface
{
    public function resolve(TenantInterface $tenant): string;
}
