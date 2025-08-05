<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Factory for creating tenant-specific entity managers.
 *
 * This factory creates entity managers with tenant-specific database connections
 * while maintaining the same ORM configuration.
 */
final readonly class TenantEntityManagerFactory
{
    public function __construct(
        private TenantConnectionResolverInterface $connectionResolver,
        private Configuration $ormConfiguration,
    ) {
    }

    /**
     * Creates an entity manager for a specific tenant.
     *
     * @param TenantInterface $tenant The tenant to create the entity manager for
     *
     * @return EntityManagerInterface The tenant-specific entity manager
     *
     * @throws \Exception If the entity manager cannot be created
     */
    public function createForTenant(TenantInterface $tenant): EntityManagerInterface
    {
        $connectionParams = $this->connectionResolver->resolveParameters($tenant);
        $connection = DriverManager::getConnection($connectionParams);

        return new EntityManager($connection, $this->ormConfiguration);
    }

    /**
     * Creates entity managers for multiple tenants.
     *
     * @param array<TenantInterface> $tenants The tenants to create entity managers for
     *
     * @return array<string, EntityManagerInterface> Array of entity managers keyed by tenant slug
     *
     * @throws \Exception
     */
    public function createForTenants(array $tenants): array
    {
        $entityManagers = [];

        foreach ($tenants as $tenant) {
            $entityManagers[$tenant->getSlug()] = $this->createForTenant($tenant);
        }

        return $entityManagers;
    }
}
