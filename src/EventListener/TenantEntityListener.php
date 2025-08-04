<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;

/**
 * Doctrine event listener that automatically sets the tenant
 * on entities that implement TenantOwnedEntityInterface.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantEntityListener
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Automatically sets the tenant on new entities before persistence.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Only process entities that implement TenantOwnedEntityInterface
        if (!$entity instanceof TenantOwnedEntityInterface) {
            return;
        }

        // Skip if tenant is already set
        if (null !== $entity->getTenant()) {
            return;
        }

        // Get current tenant from context
        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            return;
        }

        // Set the tenant on the entity
        $entity->setTenant($tenant);
    }
}
