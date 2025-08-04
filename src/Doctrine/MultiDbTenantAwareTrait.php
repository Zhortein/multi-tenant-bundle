<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Trait for entities in multi-db tenant mode.
 *
 * This trait provides tenant context access without database fields,
 * as entities exist in tenant-specific databases.
 *
 * Use this trait with #[AsTenantAware(requireTenantId: false)] attribute.
 */
trait MultiDbTenantAwareTrait
{
    /**
     * Cached tenant context (not persisted to database).
     */
    private ?TenantInterface $tenantContext = null;

    /**
     * Gets the current tenant context.
     * 
     * In multi-db mode, this is resolved from the tenant context service
     * rather than from a database relationship.
     */
    public function getTenant(): ?TenantInterface
    {
        return $this->tenantContext;
    }

    /**
     * Sets the tenant context (for internal use).
     * 
     * This method is used internally by the bundle to provide
     * tenant context to entities in multi-db mode.
     */
    public function setTenantContext(?TenantInterface $tenant): void
    {
        $this->tenantContext = $tenant;
    }
}