<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Default connection resolver that uses a single shared database.
 *
 * This implementation assumes all tenants share the same database
 * and uses tenant-based filtering instead of separate databases.
 * For separate databases per tenant, create a custom implementation.
 */
final class DefaultConnectionResolver implements TenantConnectionResolverInterface
{
    public function __construct(
        private readonly array $defaultParameters = [],
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Returns the default connection parameters for all tenants.
     * Override this class to implement per-tenant database connections.
     */
    public function resolveParameters(TenantInterface $tenant): array
    {
        // For shared database approach, return default parameters
        // For separate databases, you would implement logic like:
        // return [
        //     'dbname'   => 'tenant_' . $tenant->getSlug(),
        //     'user'     => $this->defaultParameters['user'] ?? 'postgres',
        //     'password' => $this->defaultParameters['password'] ?? '',
        //     'host'     => $this->defaultParameters['host'] ?? 'localhost',
        //     'port'     => $this->defaultParameters['port'] ?? 5432,
        //     'driver'   => 'pdo_pgsql',
        // ];

        return $this->defaultParameters;
    }

    /**
     * {@inheritdoc}
     *
     * For shared database approach, this method does nothing.
     * Override this class to implement actual connection switching for separate databases.
     */
    public function switchToTenantConnection(TenantInterface $tenant): void
    {
        // No-op for shared database approach
        // For separate databases, you would implement connection switching logic here
    }
}
