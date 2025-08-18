<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Doctrine-based tenant registry.
 *
 * Loads tenants from the database using Doctrine ORM.
 */
final readonly class DoctrineTenantRegistry implements TenantRegistryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $tenantEntityClass,
    ) {
    }

    public function getAll(): array
    {
        $repository = $this->em->getRepository($this->tenantEntityClass);

        /** @var TenantInterface[] $tenants */
        $tenants = $repository->findAll();

        return $tenants;
    }

    public function getBySlug(string $slug): TenantInterface
    {
        $tenant = $this->findBySlug($slug);

        if (null === $tenant) {
            throw new \RuntimeException(sprintf('Tenant with slug `%s` not found.', $slug));
        }

        return $tenant;
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        $repository = $this->em->getRepository($this->tenantEntityClass);
        $tenant = $repository->findOneBy(['slug' => $slug]);

        return $tenant instanceof TenantInterface ? $tenant : null;
    }

    public function hasSlug(string $slug): bool
    {
        return null !== $this->findBySlug($slug);
    }
}
