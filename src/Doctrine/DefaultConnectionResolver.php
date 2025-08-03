<?php

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class DefaultConnectionResolver implements TenantConnectionResolverInterface
{
    public function resolveParameters(TenantInterface $tenant): array
    {
        return [
            'dbname'   => $tenant->getDatabaseName(),
            'user'     => $tenant->getDatabaseUser(),
            'password' => $tenant->getDatabasePassword(),
            'host'     => $tenant->getDatabaseHost(),
            'port'     => $tenant->getDatabasePort() ?? 3306,
            'driver'   => $tenant->getDatabaseDriver() ?? 'pdo_mysql',
        ];
    }
}
