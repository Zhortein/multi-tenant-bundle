<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reversible multi-database lifecycle backed by a DBAL routing middleware.
 */
final readonly class DoctrineTenantConnectionLifecycle implements TenantConnectionLifecycleInterface, ResetInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private TenantConnectionParametersProviderInterface $parametersProvider,
        private DoctrineTenantConnectionRouter $router,
    ) {
    }

    public function prepare(TenantConnectionState $current, TenantConnectionState $target): TenantConnectionTransitionInterface
    {
        $parameters = $this->parametersProvider->parametersFor($target);
        $probe = DriverManager::getConnection($parameters);
        $probe->executeQuery('SELECT 1');

        return new class($this->managerRegistry, $this->router, $this->router->state(), $target, $probe) implements TenantConnectionTransitionInterface {
            private bool $activated = false;

            private bool $cleaned = false;

            public function __construct(
                private readonly ManagerRegistry $managerRegistry,
                private readonly DoctrineTenantConnectionRouter $router,
                private readonly TenantConnectionState $previous,
                private readonly TenantConnectionState $target,
                private readonly Connection $probe,
            ) {
            }

            public function activate(): void
            {
                if ($this->activated) {
                    throw new \LogicException('A tenant connection transition can only be activated once.');
                }

                $this->router->activate($this->target);
                $this->closeManagedConnections();
                $this->activated = true;
            }

            public function restore(): void
            {
                $this->router->activate($this->previous);
                $this->closeManagedConnections();
                $this->activated = false;
            }

            public function cleanup(): void
            {
                if (!$this->cleaned) {
                    $this->probe->close();
                    $this->cleaned = true;
                }
            }

            private function closeManagedConnections(): void
            {
                $closed = [];
                foreach ($this->managerRegistry->getManagers() as $manager) {
                    if ($manager instanceof EntityManagerInterface) {
                        $connection = $manager->getConnection();
                        $connection->close();
                        $closed[spl_object_id($connection)] = true;
                    }
                }
                foreach ($this->managerRegistry->getConnections() as $connection) {
                    if ($connection instanceof Connection && !isset($closed[spl_object_id($connection)])) {
                        $connection->close();
                    }
                }
            }
        };
    }

    public function reset(): void
    {
        // Publish NONE before closing connections so a later lazy reconnect
        // cannot reuse tenant-specific routing parameters.
        $this->router->reset();
        $this->closeManagedConnections();
    }

    private function closeManagedConnections(): void
    {
        $closed = [];
        foreach ($this->managerRegistry->getManagers() as $manager) {
            if ($manager instanceof EntityManagerInterface) {
                $connection = $manager->getConnection();
                $connection->close();
                $closed[spl_object_id($connection)] = true;
            }
        }
        foreach ($this->managerRegistry->getConnections() as $connection) {
            if ($connection instanceof Connection && !isset($closed[spl_object_id($connection)])) {
                $connection->close();
            }
        }
    }
}
