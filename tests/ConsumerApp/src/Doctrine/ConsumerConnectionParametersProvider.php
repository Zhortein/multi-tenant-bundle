<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Tools\DsnParser;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;

final readonly class ConsumerConnectionParametersProvider implements TenantConnectionParametersProviderInterface
{
    public function __construct(private string $databasePath)
    {
    }

    public function parametersFor(TenantConnectionState $state): array
    {
        $tenant = $state->tenant;
        $tenantUrls = [
            'migration-a' => $_SERVER['TENANT_DATABASE_A_URL'] ?? $_ENV['TENANT_DATABASE_A_URL'] ?? null,
            'migration-b' => $_SERVER['TENANT_DATABASE_B_URL'] ?? $_ENV['TENANT_DATABASE_B_URL'] ?? null,
        ];

        if (array_filter($tenantUrls, 'is_string')) {
            $url = null === $tenant
                ? ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null)
                : ($tenantUrls[$tenant->getSlug()] ?? null);
            if (!is_string($url) || '' === $url) {
                $target = null === $tenant ? 'the default state' : sprintf('tenant "%s"', $tenant->getSlug());

                throw new \InvalidArgumentException(sprintf('No Consumer App database is configured for %s.', $target));
            }

            return (new DsnParser([
                'postgres' => 'pdo_pgsql',
                'postgresql' => 'pdo_pgsql',
            ]))->parse($url);
        }

        return ['driver' => 'pdo_sqlite', 'path' => $this->databasePath];
    }
}
