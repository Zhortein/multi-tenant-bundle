<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\GlobalDoctrineScopeException;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;

final class GlobalDoctrineScope implements GlobalDoctrineScopeInterface, ResetInterface
{
    private bool $running = false;

    private bool $invalidated = false;

    /** @var array<string, array{string, \Doctrine\ORM\Query\FilterCollection}> */
    private array $activeFilters = [];

    public function __construct(
        private readonly ?ManagerRegistry $managerRegistry,
        private readonly ?TenantContextInterface $tenantContext = null,
        private readonly ?TenantContextSynchronizerInterface $synchronizer = null,
        private readonly string $filterName = 'tenant_filter',
    ) {
    }

    public function run(callable $operation): mixed
    {
        if ($this->running) {
            throw new GlobalDoctrineScopeException('Nested global Doctrine scopes are not supported.');
        }

        $this->running = true;
        $this->invalidated = false;
        $this->activeFilters = [];
        $tenant = $this->tenantContext?->getTenant();
        $previousState = null === $tenant ? TenantConnectionState::none() : TenantConnectionState::tenant($tenant);

        $result = null;
        $operationFailure = null;

        try {
            $this->synchronizer?->transition($previousState, TenantConnectionState::global());
            foreach ($this->managers() as $name => $manager) {
                if (!$manager instanceof EntityManagerInterface) {
                    continue;
                }

                $filters = $manager->getFilters();
                if ($filters->isEnabled($this->filterName)) {
                    try {
                        $filters->suspend($this->filterName);
                        $this->activeFilters['filter_'.spl_object_id($filters)] = [(string) $name, $filters];
                    } catch (\Throwable $exception) {
                        throw new GlobalDoctrineScopeException(sprintf('Unable to suspend tenant protection for entity manager "%s".', $name), 0, $exception);
                    }
                }
            }

            $result = $operation();
        } catch (\Throwable $exception) {
            $operationFailure = $exception;
        }

        $restorationFailure = null;
        $restorationManager = null;
        $restorable = $this->activeFilters;
        foreach ($this->managers() as $name => $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }
            $filters = $manager->getFilters();
            if ($filters->isSuspended($this->filterName)) {
                $restorable['filter_'.spl_object_id($filters)] = [(string) $name, $filters];
            }
        }
        foreach (array_reverse($restorable) as [$name, $filters]) {
            try {
                $filters->restore($this->filterName);
            } catch (\Throwable $exception) {
                if (null === $restorationFailure) {
                    $restorationFailure = $exception;
                    $restorationManager = $name;
                }
            }
        }

        $invalidated = $this->isInvalidated()
            || (null !== $this->synchronizer && TenantConnectionMode::GLOBAL !== $this->synchronizer->currentState()->mode);

        if (null === $restorationFailure && !$invalidated) {
            try {
                $this->synchronizer?->transition(TenantConnectionState::global(), $previousState);
            } catch (\Throwable $exception) {
                $restorationFailure = $exception;
                $restorationManager = 'connection lifecycle';
            }
        }

        $this->running = false;
        $this->activeFilters = [];

        if ($invalidated) {
            try {
                $this->synchronizer?->reset();
            } catch (\Throwable $exception) {
                $restorationFailure ??= $exception;
            }

            throw new GlobalDoctrineScopeException('The global Doctrine scope was invalidated by a tenant lifecycle reset.', 0, $operationFailure ?? $restorationFailure, $operationFailure ? $restorationFailure : null);
        }

        if (null !== $restorationFailure) {
            throw new GlobalDoctrineScopeException(sprintf('Unable to restore tenant protection for entity manager "%s".', $restorationManager), 0, $operationFailure ?? $restorationFailure, $operationFailure ? $restorationFailure : null);
        }
        if (null !== $operationFailure) {
            throw $operationFailure;
        }

        return $result;
    }

    /** @internal Used by ORM guards to recognize the explicit synchronous scope. */
    public function isActive(): bool
    {
        return $this->running && !$this->invalidated;
    }

    public function reset(): void
    {
        $failureTypes = [];
        if ($this->running) {
            $this->invalidated = true;
            foreach (array_reverse($this->activeFilters) as [, $filters]) {
                try {
                    if ($filters->isSuspended($this->filterName)) {
                        $filters->restore($this->filterName);
                    }
                } catch (\Throwable $failure) {
                    $failureTypes[] = $failure::class;
                }
            }
            $this->activeFilters = [];
        }

        try {
            $this->synchronizer?->reset();
        } catch (\Throwable $failure) {
            $failureTypes[] = $failure::class;
        }

        if ([] !== $failureTypes) {
            /** @var list<class-string<\Throwable>> $failureTypes */
            $failureTypes = array_values(array_unique($failureTypes));

            throw new TenantStateResetException($failureTypes);
        }
    }

    private function isInvalidated(): bool
    {
        return $this->invalidated;
    }

    /** @return array<string, object> */
    private function managers(): array
    {
        return null === $this->managerRegistry ? [] : $this->managerRegistry->getManagers();
    }
}
