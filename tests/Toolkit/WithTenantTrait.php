<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Toolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Trait providing tenant context management utilities for tests.
 *
 * This trait allows tests to:
 * - Execute code within a specific tenant context
 * - Temporarily disable Doctrine tenant filters
 * - Set PostgreSQL session variables for RLS testing
 * - Restore previous context after operations
 */
trait WithTenantTrait
{
    /**
     * Execute a callable within a specific tenant context.
     *
     * This method:
     * 1. Resolves the tenant by ID/slug
     * 2. Sets the tenant in the bundle's TenantContext
     * 3. Sets the PostgreSQL session variable for RLS
     * 4. Executes the callable
     * 5. Restores the previous context and clears the session variable
     *
     * @param string   $tenantId The tenant ID or slug
     * @param callable $fn       The callable to execute
     *
     * @return mixed The result of the callable
     *
     * @throws \RuntimeException If tenant context or entity manager is not available
     */
    protected function withTenant(string $tenantId, callable $fn): mixed
    {
        $tenantContext = $this->getTenantContext();
        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();

        // Store previous context
        $previousTenant = $tenantContext->getTenant();

        try {
            // Resolve and set the tenant
            $tenant = $this->resolveTenant($tenantId);
            $tenantContext->setTenant($tenant);

            // Set PostgreSQL session variable for RLS
            $this->setPostgreSQLTenantId($connection, (string) $tenant->getId());

            // Execute the callable
            return $fn();
        } finally {
            // Restore previous context
            if ($previousTenant) {
                $tenantContext->setTenant($previousTenant);
                $this->setPostgreSQLTenantId($connection, (string) $previousTenant->getId());
            } else {
                $tenantContext->clear();
                $this->clearPostgreSQLTenantId($connection);
            }
        }
    }

    /**
     * Execute a callable with the Doctrine tenant filter temporarily disabled.
     *
     * This method:
     * 1. Disables the TenantDoctrineFilter
     * 2. Executes the callable
     * 3. Re-enables the filter if it was previously enabled
     *
     * @param callable $fn The callable to execute
     *
     * @return mixed The result of the callable
     *
     * @throws \RuntimeException If entity manager is not available
     */
    protected function withoutDoctrineTenantFilter(callable $fn): mixed
    {
        $entityManager = $this->getEntityManager();
        $filterCollection = $entityManager->getFilters();

        // Check if the filter is currently enabled
        $filterName = $this->getTenantFilterName();
        $wasEnabled = $filterCollection->isEnabled($filterName);

        try {
            // Disable the filter if it was enabled
            if ($wasEnabled) {
                $filterCollection->disable($filterName);
            }

            // Execute the callable
            return $fn();
        } finally {
            // Re-enable the filter if it was previously enabled
            if ($wasEnabled) {
                $filterCollection->enable($filterName);
            }
        }
    }

    /**
     * Get the tenant context service.
     *
     * This method should be implemented by the test class to provide
     * access to the TenantContextInterface service.
     */
    abstract protected function getTenantContext(): TenantContextInterface;

    /**
     * Get the entity manager service.
     *
     * This method should be implemented by the test class to provide
     * access to the EntityManagerInterface service.
     */
    abstract protected function getEntityManager(): EntityManagerInterface;

    /**
     * Get the tenant registry service.
     *
     * This method should be implemented by the test class to provide
     * access to the TenantRegistryInterface service.
     */
    abstract protected function getTenantRegistry(): TenantRegistryInterface;

    /**
     * Resolve a tenant by ID or slug.
     *
     * @param string $tenantId The tenant ID or slug
     *
     * @return TenantInterface The resolved tenant
     *
     * @throws \RuntimeException If the tenant cannot be found
     */
    private function resolveTenant(string $tenantId): TenantInterface
    {
        $tenantRegistry = $this->getTenantRegistry();

        // Try to find by slug first
        $tenant = $tenantRegistry->findBySlug($tenantId);

        if (!$tenant) {
            // Try to find by ID if slug lookup failed
            $tenant = $tenantRegistry->findById($tenantId);
        }

        if (!$tenant) {
            throw new \RuntimeException(sprintf('Tenant with ID/slug "%s" not found', $tenantId));
        }

        return $tenant;
    }

    /**
     * Set the PostgreSQL session variable for the current tenant.
     *
     * @param Connection $connection The database connection
     * @param string     $tenantId   The tenant ID
     */
    private function setPostgreSQLTenantId(Connection $connection, string $tenantId): void
    {
        $connection->executeStatement(
            'SELECT set_config(?, ?, true)',
            ['app.tenant_id', $tenantId]
        );
    }

    /**
     * Clear the PostgreSQL session variable.
     *
     * @param Connection $connection The database connection
     */
    private function clearPostgreSQLTenantId(Connection $connection): void
    {
        $connection->executeStatement(
            'SELECT set_config(?, NULL, true)',
            ['app.tenant_id']
        );
    }

    /**
     * Get the name of the tenant Doctrine filter.
     *
     * @return string The filter name
     */
    private function getTenantFilterName(): string
    {
        // The filter name is typically the class name without namespace
        return 'tenant_doctrine_filter';
    }
}
