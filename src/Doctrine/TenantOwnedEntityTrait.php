<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Trait for entities that belong to a specific tenant.
 *
 * This trait provides the basic tenant association and implements
 * the TenantOwnedEntityInterface for automatic filtering.
 */
trait TenantOwnedEntityTrait
{
    /**
     * The tenant that owns this entity.
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