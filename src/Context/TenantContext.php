<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Context;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;

/**
 * Holds the tenant context for the current request lifecycle.
 */
final class TenantContext implements TenantContextInterface
{
    private ?TenantInterface $tenant = null;

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $previousTenant = $this->tenant;
        $this->tenant = $tenant;

        // Dispatch context ended event for previous tenant
        if (null !== $previousTenant && null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new TenantContextEndedEvent((string) $previousTenant->getId())
            );
        }

        // Dispatch context started event for new tenant
        if (null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new TenantContextStartedEvent((string) $tenant->getId())
            );
        }
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return null !== $this->tenant;
    }

    public function clear(): void
    {
        $previousTenant = $this->tenant;
        $this->tenant = null;

        // Dispatch context ended event
        if (null !== $previousTenant && null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new TenantContextEndedEvent((string) $previousTenant->getId())
            );
        }
    }
}
