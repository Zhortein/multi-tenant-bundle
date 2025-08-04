<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Registry;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Registry interface for accessing tenant entities.
 *
 * Provides methods to retrieve tenants from various storage backends.
 */
interface TenantRegistryInterface
{
    /**
     * Retrieves all known tenants.
     *
     * @return TenantInterface[] Array of all tenant entities
     */
    public function getAll(): array;

    /**
     * Retrieves a tenant by its slug.
     *
     * @param string $slug The tenant slug
     *
     * @return TenantInterface The tenant entity
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function getBySlug(string $slug): TenantInterface;

    /**
     * Checks if a tenant exists by slug.
     *
     * @param string $slug The tenant slug
     *
     * @return bool True if tenant exists, false otherwise
     */
    public function hasSlug(string $slug): bool;
}
