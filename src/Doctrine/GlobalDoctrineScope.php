<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\GlobalDoctrineScopeException;

final class GlobalDoctrineScope implements GlobalDoctrineScopeInterface
{
    private bool $running = false;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
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
        $suspended = [];
        $tenant = $this->tenantContext?->getTenant();
        $previousState = null === $tenant ? TenantConnectionState::none() : TenantConnectionState::tenant($tenant);

        $result = null;
        $operationFailure = null;

        try {
            $this->synchronizer?->transition($previousState, TenantConnectionState::global());
            foreach ($this->managerRegistry->getManagers() as $name => $manager) {
                if (!$manager instanceof EntityManagerInterface) {
                    continue;
                }

                $filters = $manager->getFilters();
                if ($filters->isEnabled($this->filterName)) {
                    try {
                        $filters->suspend($this->filterName);
                        $suspended[(string) spl_object_id($filters)] = [$name, $filters];
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
        $restorable = $suspended;
        foreach ($this->managerRegistry->getManagers() as $name => $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }
            $filters = $manager->getFilters();
            if ($filters->isSuspended($this->filterName)) {
                $restorable[(string) spl_object_id($filters)] = [$name, $filters];
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

        if (null === $restorationFailure) {
            try {
                $this->synchronizer?->transition(TenantConnectionState::global(), $previousState);
            } catch (\Throwable $exception) {
                $restorationFailure = $exception;
                $restorationManager = 'connection lifecycle';
            }
        }

        $this->running = false;

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
        return $this->running;
    }
}
