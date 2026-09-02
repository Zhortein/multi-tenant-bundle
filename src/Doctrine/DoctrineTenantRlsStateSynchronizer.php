<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @internal Applies RLS state before a TenantContext transition is published. */
final readonly class DoctrineTenantRlsStateSynchronizer implements TenantRlsStateSynchronizerInterface
{
    public function __construct(
        private ?ManagerRegistry $managerRegistry,
        private bool $enabled,
        private string $sessionVariable = 'app.tenant_id',
    ) {
    }

    public function apply(TenantConnectionState $state): void
    {
        if (!$this->enabled || null === $this->managerRegistry) {
            return;
        }

        $tenantId = TenantConnectionMode::TENANT === $state->mode ? (string) $state->tenant?->getId() : '';
        foreach ($this->managerRegistry->getManagers() as $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }
            $connection = $manager->getConnection();
            if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                continue;
            }
            $connection->executeStatement('SELECT set_config(?, ?, false)', [$this->sessionVariable, $tenantId]);
        }
    }
}
