<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Zhortein\MultiTenantBundle\Exception\DirtyEntityManagerException;
use Zhortein\MultiTenantBundle\Exception\DoctrineProtectionException;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;

class DoctrineTenantContextSynchronizer implements TenantContextSynchronizerInterface
{
    private TenantConnectionState $state;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
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

    /** @return array<string, EntityManagerInterface> */
    private function entityManagers(): array
    {
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
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        if ([] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
            || [] !== $unitOfWork->getScheduledCollectionUpdates()
            || [] !== $unitOfWork->getScheduledCollectionDeletions()) {
            throw new DirtyEntityManagerException(sprintf('Tenant context cannot change while entity manager "%s" has unflushed changes.', $name));
        }
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
}
