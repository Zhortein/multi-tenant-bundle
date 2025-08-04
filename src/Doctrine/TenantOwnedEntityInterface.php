<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Interface for entities that belong to a specific tenant.
 *
 * Entities implementing this interface will be automatically filtered
 * by the TenantDoctrineFilter to only show data for the current tenant.
 */
interface TenantOwnedEntityInterface
{
    /**
     * Gets the tenant that owns this entity.
     *
     * @return TenantInterface|null The owning tenant or null if not set
     */
    public function getTenant(): ?TenantInterface;

    /**
     * Sets the tenant that owns this entity.
     *
     * @param TenantInterface $tenant The tenant to set
     */
    public function setTenant(TenantInterface $tenant): void;
}
