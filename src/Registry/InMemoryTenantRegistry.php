<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Registry;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;

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
        $tenant = $this->findBySlug($slug);

        if (null === $tenant) {
            throw new TenantNotFoundException(sprintf('Tenant with slug `%s` not found.', $slug));
        }

        return $tenant;
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->getSlug() === $slug) {
                return $tenant;
            }
        }

        return null;
    }

    public function findById(string|int $id): ?TenantInterface
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->getId() === $id || (string) $tenant->getId() === (string) $id) {
                return $tenant;
            }
        }

        return null;
    }

    public function hasSlug(string $slug): bool
    {
        return null !== $this->findBySlug($slug);
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
