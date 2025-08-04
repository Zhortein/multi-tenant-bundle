<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Trait for entities that are tenant-aware.
 *
 * This trait provides tenant association for shared-db mode.
 * In multi-db mode, entities don't need tenant_id as they exist
 * in tenant-specific databases.
 *
 * Use this trait with #[AsTenantAware] attribute.
 */
trait TenantAwareEntityTrait
{
    /**
     * The tenant that owns this entity (shared-db mode only).
     */
    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    /**
     * Gets the tenant that owns this entity.
     */
    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    /**
     * Sets the tenant that owns this entity.
     */
    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}