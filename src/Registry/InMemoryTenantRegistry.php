<?php

namespace Zhortein\MultiTenantBundle\Registry;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class InMemoryTenantRegistry implements TenantRegistryInterface
{
    /**
     * @param TenantInterface[] $tenants
     */
    public function __construct(private array $tenants = []) {}

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

        throw new \RuntimeException("Tenant with slug '{$slug}' not found.");
    }
}
