<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Exception\DirtyEntityManagerException;
use Zhortein\MultiTenantBundle\Exception\DoctrineProtectionException;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;

class DoctrineTenantContextSynchronizer implements TenantContextSynchronizerInterface
{
    private TenantConnectionState $state;

    public function __construct(
        private readonly ?ManagerRegistry $managerRegistry,
        private readonly TenantConnectionLifecycleInterface $connectionLifecycle,
        private readonly ?TenantRlsStateSynchronizerInterface $rlsSynchronizer = null,
        private readonly string $filterName = 'tenant_filter',
    ) {
        $this->state = TenantConnectionState::none();
    }

    public function currentState(): TenantConnectionState
    {
        return $this->state;
    }

    public function transition(TenantConnectionState $current, TenantConnectionState $target): void
    {
        $managers = $this->entityManagers();
        foreach ($managers as $name => $manager) {
            $this->assertClean($manager, $name);
        }

        $connectionTransition = $this->connectionLifecycle->prepare($current, $target);
        $rlsConfigured = false;
        $configured = [];

        try {
            $connectionTransition->activate();
            $rlsConfigured = true;
            $this->rlsSynchronizer?->apply($target);
            foreach ($managers as $name => $manager) {
                $configured[] = [$name, $manager];
                $this->configureFilter($manager, $target, $name);
            }
            foreach ($managers as $manager) {
                $manager->clear();
            }
        } catch (\Throwable $failure) {
            $restorationFailure = null;
            foreach (array_reverse($configured) as [$name, $manager]) {
                try {
                    $this->configureFilter($manager, $current, $name);
                } catch (\Throwable $exception) {
                    $restorationFailure ??= $exception;
                }
            }
            if ($rlsConfigured) {
                try {
                    $this->rlsSynchronizer?->apply($current);
                } catch (\Throwable $exception) {
                    $restorationFailure ??= $exception;
                }
            }
            try {
                $connectionTransition->restore();
            } catch (\Throwable $exception) {
                $restorationFailure ??= $exception;
            }
            $cleanupFailure = $this->cleanup($connectionTransition);

            throw new TenantContextTransitionException('Unable to change tenant connection state safely.', 0, $failure, $restorationFailure, $cleanupFailure);
        }

        $cleanupFailure = $this->cleanup($connectionTransition);
        if (null !== $cleanupFailure) {
            $restorationFailure = null;
            foreach (array_reverse($configured) as [$name, $manager]) {
                try {
                    $this->configureFilter($manager, $current, $name);
                } catch (\Throwable $exception) {
                    $restorationFailure ??= $exception;
                }
            }
            try {
                $this->rlsSynchronizer?->apply($current);
                $connectionTransition->restore();
            } catch (\Throwable $exception) {
                $restorationFailure ??= $exception;
            }

            throw new TenantContextTransitionException('The tenant transition was rolled back because its temporary resources could not be cleaned up.', 0, $cleanupFailure, $restorationFailure, $cleanupFailure);
        }

        $this->state = $target;
    }

    public function reset(): void
    {
        $previousState = $this->state;
        // The logical state is invalidated first. No subsequent code may treat
        // the former tenant as active, even when physical cleanup fails.
        $this->state = TenantConnectionState::none();

        $managers = $this->entityManagers();
        $quarantined = [];
        $failureTypes = [];

        foreach ($managers as $name => $manager) {
            if (!$manager->isOpen()) {
                $this->quarantine($manager, $name, $quarantined, $failureTypes);

                continue;
            }

            try {
                if ($this->isDirty($manager)) {
                    $failureTypes[] = DirtyEntityManagerException::class;
                    $this->quarantine($manager, $name, $quarantined, $failureTypes);

                    continue;
                }

                $this->configureFilter($manager, TenantConnectionState::none(), $name);
                $manager->clear();
            } catch (\Throwable $failure) {
                $failureTypes[] = $failure::class;
                $this->quarantine($manager, $name, $quarantined, $failureTypes);
            }
        }

        try {
            $this->rlsSynchronizer?->apply(TenantConnectionState::none());
        } catch (\Throwable $failure) {
            $failureTypes[] = $failure::class;
            foreach ($managers as $name => $manager) {
                $this->quarantine($manager, $name, $quarantined, $failureTypes);
            }
        }

        try {
            $this->resetConnectionLifecycle($previousState);
        } catch (\Throwable $failure) {
            $failureTypes[] = $failure::class;
            foreach ($managers as $name => $manager) {
                $this->quarantine($manager, $name, $quarantined, $failureTypes);
            }
        }

        foreach (array_keys($quarantined) as $name) {
            try {
                $this->managerRegistry?->resetManager($name);
            } catch (\Throwable $failure) {
                $failureTypes[] = $failure::class;
            }
        }

        if ([] !== $failureTypes) {
            /** @var list<class-string<\Throwable>> $failureTypes */
            $failureTypes = array_values(array_unique($failureTypes));

            throw new TenantStateResetException($failureTypes);
        }
    }

    /** @return array<string, EntityManagerInterface> */
    private function entityManagers(): array
    {
        if (null === $this->managerRegistry) {
            return [];
        }

        $managers = [];
        foreach ($this->managerRegistry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface) {
                $managers[(string) $name] = $manager;
            }
        }

        return $managers;
    }

    private function assertClean(EntityManagerInterface $manager, string $name): void
    {
        if ($this->isDirty($manager)) {
            throw new DirtyEntityManagerException(sprintf('Tenant context cannot change while entity manager "%s" has unflushed changes.', $name));
        }
    }

    private function isDirty(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
            || [] !== $unitOfWork->getScheduledCollectionUpdates()
            || [] !== $unitOfWork->getScheduledCollectionDeletions();
    }

    private function configureFilter(EntityManagerInterface $manager, TenantConnectionState $state, string $name): void
    {
        $filters = $manager->getFilters();
        if (!$filters->isEnabled($this->filterName)) {
            throw new DoctrineProtectionException(sprintf('Tenant protection is not active for entity manager "%s".', $name));
        }

        $filter = $filters->getFilter($this->filterName);
        $filter->setParameter('tenant_context_mode', $state->mode->value);
        $filter->setParameter('tenant_id', TenantConnectionMode::TENANT === $state->mode ? (string) $state->tenant?->getId() : '__NO_TENANT__');
    }

    private function cleanup(TenantConnectionTransitionInterface $transition): ?\Throwable
    {
        try {
            $transition->cleanup();

            return null;
        } catch (\Throwable $exception) {
            return $exception;
        }
    }

    /**
     * @param array<string, true>            $quarantined
     * @param list<class-string<\Throwable>> $failureTypes
     */
    private function quarantine(EntityManagerInterface $manager, string $name, array &$quarantined, array &$failureTypes): void
    {
        try {
            $manager->getConnection()->close();
        } catch (\Throwable $failure) {
            $failureTypes[] = $failure::class;
        }

        try {
            $manager->close();
        } catch (\Throwable $failure) {
            $failureTypes[] = $failure::class;
        }

        $quarantined[$name] = true;
    }

    private function resetConnectionLifecycle(TenantConnectionState $previousState): void
    {
        if ($this->connectionLifecycle instanceof ResetInterface) {
            $this->connectionLifecycle->reset();

            return;
        }

        $transition = $this->connectionLifecycle->prepare($previousState, TenantConnectionState::none());
        try {
            $transition->activate();
        } finally {
            $transition->cleanup();
        }
    }
}
