<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Registry;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * In-memory tenant registry.
 *
 * Useful for testing or when tenants are configured statically.
 */
final class InMemoryTenantRegistry implements TenantRegistryInterface
{
    /**
     * @param TenantInterface[] $tenants Array of tenant entities
     */
    public function __construct(private array $tenants = [])
    {
    }

    public function getAll(): array
    {
        return $this->tenants;
    }

    public function getBySlug(string $slug): TenantInterface
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->getSlug() === $slug) {
                return $tenant;
            }
        }

        throw new \Zhortein\MultiTenantBundle\Exception\TenantNotFoundException("Tenant with slug '{$slug}' not found.");
    }

    public function hasSlug(string $slug): bool
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a tenant to the registry.
     *
     * @param TenantInterface $tenant The tenant to add
     */
    public function addTenant(TenantInterface $tenant): void
    {
        $this->tenants[] = $tenant;
    }

    /**
     * Removes a tenant from the registry by slug.
     *
     * @param string $slug The tenant slug to remove
     *
     * @return bool True if tenant was removed, false if not found
     */
    public function removeTenant(string $slug): bool
    {
        foreach ($this->tenants as $index => $tenant) {
            if ($tenant->getSlug() === $slug) {
                unset($this->tenants[$index]);
                $this->tenants = array_values($this->tenants); // Re-index array

                return true;
            }
        }

        return false;
    }
}
