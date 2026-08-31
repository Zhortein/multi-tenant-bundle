<?php

declare(strict_types=1);

namespace App\Doctrine;

use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;

final readonly class ConsumerConnectionParametersProvider implements TenantConnectionParametersProviderInterface
{
    public function __construct(private string $databasePath)
    {
    }

    public function parametersFor(TenantConnectionState $state): array
    {
        return ['driver' => 'pdo_sqlite', 'path' => $this->databasePath];
    }
}
