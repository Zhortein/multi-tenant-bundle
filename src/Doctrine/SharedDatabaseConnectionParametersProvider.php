<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** @internal Shared-database parameters used by explicit administrative factories. */
/** @phpstan-import-type Params from DriverManager */
final readonly class SharedDatabaseConnectionParametersProvider implements TenantConnectionParametersProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function parametersFor(TenantConnectionState $state): array
    {
        /** @var Params $parameters */
        $parameters = $this->connection->getParams();

        return $parameters;
    }
}
