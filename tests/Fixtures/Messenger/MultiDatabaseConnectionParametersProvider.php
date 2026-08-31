<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionMode;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Exception\TenantConnectionConfigurationException;

final readonly class MultiDatabaseConnectionParametersProvider implements TenantConnectionParametersProviderInterface
{
    public function parametersFor(TenantConnectionState $state): array
    {
        $database = match ($state->mode) {
            TenantConnectionMode::GLOBAL, TenantConnectionMode::NONE => 'messenger_global_test',
            TenantConnectionMode::TENANT => match ($state->tenant?->getSlug()) {
                'tenant-a' => 'messenger_tenant_a_test',
                'tenant-b' => 'messenger_tenant_b_test',
                default => throw new TenantConnectionConfigurationException('No connection is configured for the requested tenant.'),
            },
        };

        return [
            'driver' => 'pdo_pgsql',
            'host' => (string) ($_SERVER['TEST_DATABASE_HOST'] ?? 'postgres'),
            'port' => (int) ($_SERVER['TEST_DATABASE_PORT'] ?? 5432),
            'dbname' => $database,
            'user' => (string) ($_SERVER['TEST_DATABASE_USER'] ?? 'test_user'),
            'password' => (string) ($_SERVER['TEST_DATABASE_PASSWORD'] ?? ''),
            'serverVersion' => (string) ($_SERVER['TEST_DATABASE_SERVER_VERSION'] ?? '16'),
        ];
    }
}
