<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Interface for resolving database connection parameters for tenants.
 *
 * Implementations should return Doctrine DBAL connection parameters
 * specific to each tenant's database configuration.
 */
interface TenantConnectionResolverInterface
{
    /**
     * Resolves database connection parameters for a tenant.
     *
     * @param TenantInterface $tenant The tenant to resolve parameters for
     *
     * @return array<string, mixed> Doctrine DBAL connection parameters
     */
    public function resolveParameters(TenantInterface $tenant): array;

    /**
     * Switches the active database connection to the tenant's database.
     *
     * @param TenantInterface $tenant The tenant to switch connection for
     */
    public function switchToTenantConnection(TenantInterface $tenant): void;
}
