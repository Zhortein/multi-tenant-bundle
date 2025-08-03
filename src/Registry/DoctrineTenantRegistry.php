<?php

namespace Zhortein\MultiTenantBundle\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Load all tenants via Doctrine.
 */
final class DoctrineTenantRegistry implements TenantRegistryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
    ) {}

    public function getAll(): array
    {
        return $this->em->getRepository($this->tenantEntityClass)->findAll();
    }

    public function getBySlug(string $slug): TenantInterface
    {
        $tenant = $this->em->getRepository($this->tenantEntityClass)->findOneBy(['slug' => $slug]);

        if (!$tenant instanceof TenantInterface) {
            throw new \RuntimeException("Tenant with slug '{$slug}' not found.");
        }

        return $tenant;
    }
}
