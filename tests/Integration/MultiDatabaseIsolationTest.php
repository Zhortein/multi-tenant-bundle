<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

#[Group('rls')]
final class MultiDatabaseIsolationTest extends TestCase
{
    private Connection $adminConnection;

    /** @var array<string, mixed> */
    private array $baseParameters;

    protected function setUp(): void
    {
        $this->baseParameters = [
            'driver' => 'pdo_pgsql',
            'host' => $this->environment('TEST_DATABASE_HOST', '127.0.0.1'),
            'port' => (int) $this->environment('TEST_DATABASE_PORT', '5432'),
            'dbname' => $this->environment('TEST_DATABASE_NAME', 'multi_tenant_test'),
            'user' => $this->environment('TEST_DATABASE_USER', 'test_user'),
            'password' => $this->environment('TEST_DATABASE_PASSWORD', 'test_password'),
        ];

        try {
            $this->adminConnection = DriverManager::getConnection($this->baseParameters);
            $this->adminConnection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            if ('1' === $this->environment('TEST_DATABASE_REQUIRED', '0')) {
                self::fail('The required PostgreSQL multi-database test environment is unavailable: '.$exception->getMessage());
            }

            self::markTestSkipped('PostgreSQL is required for multi-database isolation tests.');
        }

        foreach (['multi_tenant_a_test', 'multi_tenant_b_test'] as $databaseName) {
            if (false === $this->adminConnection->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName])) {
                $this->adminConnection->executeStatement(sprintf('CREATE DATABASE %s', $this->adminConnection->quoteIdentifier($databaseName)));
            }

            $connection = DriverManager::getConnection(array_merge($this->baseParameters, ['dbname' => $databaseName]));
            $connection->executeStatement('CREATE TABLE IF NOT EXISTS isolation_probe (marker VARCHAR(64) NOT NULL)');
            $connection->executeStatement('TRUNCATE isolation_probe');
            $connection->close();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->adminConnection)) {
            $this->adminConnection->close();
        }
    }

    public function testFreshConnectionsPreserveIsolationAcrossTenantRotation(): void
    {
        $tenantA = (new TestTenant())->setId(101)->setSlug('tenant-a')->setName('Tenant A');
        $tenantB = (new TestTenant())->setId(202)->setSlug('tenant-b')->setName('Tenant B');
        $factory = $this->factory();

        $connectionIds = [];
        $databases = [];

        foreach ([[$tenantA, 'A-1'], [$tenantB, 'B-1'], [$tenantA, 'A-2']] as [$tenant, $marker]) {
            $databases[] = $factory->runForTenant(
                $tenant,
                function (EntityManagerInterface $entityManager) use (&$connectionIds, $marker): string {
                    $connection = $entityManager->getConnection();
                    $connectionIds[] = spl_object_id($connection);
                    $connection->insert('isolation_probe', ['marker' => $marker]);

                    return (string) $connection->fetchOne('SELECT current_database()');
                }
            );
        }

        self::assertSame(['multi_tenant_a_test', 'multi_tenant_b_test', 'multi_tenant_a_test'], $databases);
        self::assertCount(3, array_unique($connectionIds));
        self::assertSame(['A-1', 'A-2'], $this->markers('multi_tenant_a_test'));
        self::assertSame(['B-1'], $this->markers('multi_tenant_b_test'));
        self::assertNotContains('B-1', $this->markers('multi_tenant_a_test'));
        self::assertNotContains('A-1', $this->markers('multi_tenant_b_test'));
    }

    public function testConnectionClosesWhenTenantWorkFails(): void
    {
        $tenant = (new TestTenant())->setId(101)->setSlug('tenant-a')->setName('Tenant A');
        $capturedConnection = null;

        try {
            $this->factory()->runForTenant(
                $tenant,
                static function (EntityManagerInterface $entityManager) use (&$capturedConnection): never {
                    $capturedConnection = $entityManager->getConnection();
                    $capturedConnection->executeQuery('SELECT 1');

                    throw new \RuntimeException('Tenant operation failed.');
                }
            );
            self::fail('The tenant exception should have propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Tenant operation failed.', $exception->getMessage());
        }

        self::assertInstanceOf(Connection::class, $capturedConnection);
        self::assertFalse($capturedConnection->isConnected());
    }

    private function factory(): TenantEntityManagerFactory
    {
        $parameters = $this->baseParameters;
        $provider = new class($parameters) implements TenantConnectionParametersProviderInterface {
            /** @param array<string, mixed> $parameters */
            public function __construct(private readonly array $parameters)
            {
            }

            public function parametersFor(TenantConnectionState $state): array
            {
                $tenant = $state->tenant ?? throw new \InvalidArgumentException('This test factory requires a tenant state.');
                $database = match ($tenant->getSlug()) {
                    'tenant-a' => 'multi_tenant_a_test',
                    'tenant-b' => 'multi_tenant_b_test',
                    default => throw new \InvalidArgumentException('No database is configured for tenant "'.$tenant->getSlug().'".'),
                };

                return array_merge($this->parameters, ['dbname' => $database]);
            }
        };

        $ormConfiguration = ORMSetup::createAttributeMetadataConfiguration([], true);

        if (PHP_VERSION_ID >= 80400) {
            $ormConfiguration->enableNativeLazyObjects(true);
        }

        return new TenantEntityManagerFactory($provider, $ormConfiguration);
    }

    /** @return list<string> */
    private function markers(string $databaseName): array
    {
        $connection = DriverManager::getConnection(array_merge($this->baseParameters, ['dbname' => $databaseName]));

        try {
            return array_values(array_map(
                static fn (array $row): string => (string) $row['marker'],
                $connection->fetchAllAssociative('SELECT marker FROM isolation_probe ORDER BY marker')
            ));
        } finally {
            $connection->close();
        }
    }

    private function environment(string $name, string $default): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
