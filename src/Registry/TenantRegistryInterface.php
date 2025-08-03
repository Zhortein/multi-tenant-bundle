<?php

namespace Zhortein\MultiTenantBundle\Registry;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Provide access to all known tenants.
 */
interface TenantRegistryInterface
{
    /**
     * @return TenantInterface[] Returns all known tenants.
     */
    public function getAll(): array;

    /**
     * @throws \RuntimeException If not found.
     */
    public function getBySlug(string $slug): TenantInterface;
}
