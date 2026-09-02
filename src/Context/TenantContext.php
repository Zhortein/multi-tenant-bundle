<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Context;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;
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
        private readonly ?TenantContextSynchronizerInterface $synchronizer = null,
        /** @var iterable<ResetInterface> */
        private readonly iterable $derivedStateResetters = [],
    ) {
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $previousTenant = $this->tenant;
        $this->synchronizer?->transition(
            $this->synchronizer->currentState(),
            TenantConnectionState::tenant($tenant),
        );
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
        $this->synchronizer?->transition(
            $this->synchronizer->currentState(),
            TenantConnectionState::none(),
        );
        $this->tenant = null;

        // Dispatch context ended event
        if (null !== $previousTenant && null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new TenantContextEndedEvent((string) $previousTenant->getId())
            );
        }
    }

    public function reset(): void
    {
        $previousTenant = $this->tenant;
        $failureTypes = [];

        // Logical invalidation must happen before any fallible I/O cleanup.
        $this->tenant = null;

        foreach ($this->derivedStateResetters as $resetter) {
            try {
                $resetter->reset();
            } catch (\Throwable $failure) {
                $failureTypes = $this->mergeFailureTypes($failureTypes, $failure);
            }
        }

        try {
            $this->synchronizer?->reset();
        } catch (\Throwable $failure) {
            $failureTypes = $this->mergeFailureTypes($failureTypes, $failure);
        }

        if (null !== $previousTenant && null !== $this->eventDispatcher) {
            try {
                $this->eventDispatcher->dispatch(
                    new TenantContextEndedEvent((string) $previousTenant->getId())
                );
            } catch (\Throwable $failure) {
                $failureTypes = $this->mergeFailureTypes($failureTypes, $failure);
            }
        }

        if ([] !== $failureTypes) {
            /** @var list<class-string<\Throwable>> $failureTypes */
            $failureTypes = array_values(array_unique($failureTypes));

            throw new TenantStateResetException($failureTypes);
        }
    }

    /**
     * @param list<class-string<\Throwable>> $failureTypes
     *
     * @return list<class-string<\Throwable>>
     */
    private function mergeFailureTypes(array $failureTypes, \Throwable $failure): array
    {
        if ($failure instanceof TenantStateResetException) {
            return [...$failureTypes, ...$failure->getFailureTypes()];
        }

        $failureTypes[] = $failure::class;

        return $failureTypes;
    }
}
