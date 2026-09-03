<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Zhortein\MultiTenantBundle\Command\MigrateTenantsCommand;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand\Version20260903010000;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand\Version20260903020000;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand\Version20260903030000;

#[Group('tenant-migrate')]
final class MigrateTenantsCommandTest extends TestCase
{
    private const METADATA_TABLE = 'tenant_migrate_test_versions';

    private Connection $adminConnection;

    /** @var array<string, mixed> */
    private array $baseParameters;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            if ('1' === $this->environment('TEST_DATABASE_REQUIRED', '0')) {
                self::fail('The required pdo_pgsql extension is unavailable.');
            }

            self::markTestSkipped('pdo_pgsql is required for tenant:migrate functional tests.');
        }

        $this->baseParameters = [
            'driver' => 'pdo_pgsql',
            'host' => $this->environment('TEST_DATABASE_HOST', '127.0.0.1'),
            'port' => (int) $this->environment('TEST_DATABASE_PORT', '5432'),
            'dbname' => $this->environment('TEST_DATABASE_NAME', 'multi_tenant_test'),
            'user' => $this->environment('TEST_DATABASE_USER', 'test_user'),
            'password' => $this->environment('TEST_DATABASE_PASSWORD', 'test_password'),
            'serverVersion' => $this->environment('TEST_DATABASE_SERVER_VERSION', '16'),
        ];

        try {
            $this->adminConnection = DriverManager::getConnection($this->baseParameters);
            $this->adminConnection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            if ('1' === $this->environment('TEST_DATABASE_REQUIRED', '0')) {
                self::fail('The required PostgreSQL tenant migration environment is unavailable: '.$exception->getMessage());
            }

            self::markTestSkipped('PostgreSQL is required for tenant:migrate functional tests.');
        }

        foreach ($this->databaseNames() as $databaseName) {
            if (false === $this->adminConnection->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName])) {
                $this->adminConnection->executeStatement(sprintf('CREATE DATABASE %s', $this->adminConnection->quoteIdentifier($databaseName)));
            }

            $this->resetDatabase($databaseName);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->adminConnection)) {
            return;
        }

        foreach ($this->databaseNames() as $databaseName) {
            $this->resetDatabase($databaseName);
        }

        $this->adminConnection->close();
    }

    public function testSharedDatabaseDryRunMigrationAndIdempotenceUseTheLatestPlan(): void
    {
        $connection = $this->connection('tenant_migrate_shared_test');
        $configuration = $this->successfulConfiguration();
        $context = new TenantContext();
        $command = $this->command(
            'shared_db',
            $configuration,
            new InMemoryTenantRegistry(),
            $context,
            $this->rejectingProvider(),
            $connection,
        );

        [$dryRunStatus, $dryRunOutput] = $this->runCommand($command, ['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $dryRunStatus, $dryRunOutput);
        self::assertStringContainsString('CREATE TABLE tenant_migration_probe', $dryRunOutput);
        self::assertStringContainsString('ALTER TABLE tenant_migration_probe', $dryRunOutput);
        self::assertFalse($this->tableExists($connection, 'tenant_migration_probe'));
        self::assertFalse($this->tableExists($connection, self::METADATA_TABLE));
        self::assertSame(0, $connection->getTransactionNestingLevel());

        [$migrationStatus, $migrationOutput] = $this->runCommand($command);

        self::assertSame(Command::SUCCESS, $migrationStatus, $migrationOutput);
        self::assertStringContainsString('Successfully executed 2 migrations.', $migrationOutput);
        $this->assertDatabaseMigrated($connection);
        self::assertSame(0, $connection->getTransactionNestingLevel());

        [$secondStatus, $secondOutput] = $this->runCommand($command);

        self::assertSame(Command::SUCCESS, $secondStatus, $secondOutput);
        self::assertStringContainsString('No migrations to execute.', $secondOutput);
        $this->assertDatabaseMigrated($connection);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertNull($context->getTenant());

        $connection->close();
    }

    public function testMultiDatabaseDryRunThenTenantAThenTenantBThenTenantAIsIsolated(): void
    {
        $tenantA = $this->tenant(101, 'tenant-a');
        $tenantB = $this->tenant(202, 'tenant-b');
        $registry = new InMemoryTenantRegistry([$tenantB, $tenantA]);
        $context = new TenantContext();
        $provider = $this->recordingProvider();
        $defaultConnection = $this->connection('tenant_migrate_shared_test');
        $command = $this->command(
            'multi_db',
            $this->successfulConfiguration(),
            $registry,
            $context,
            $provider,
            $defaultConnection,
        );

        [$dryRunStatus, $dryRunOutput] = $this->runCommand($command, ['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $dryRunStatus, $dryRunOutput);
        self::assertLessThan(
            strpos($dryRunOutput, 'Migrating tenant: tenant-b'),
            strpos($dryRunOutput, 'Migrating tenant: tenant-a'),
        );
        self::assertSame(['tenant-a', 'tenant-b'], $provider->requestedTenants);
        $this->assertDatabaseUnchangedByDryRun('tenant_migrate_tenant_a_test');
        $this->assertDatabaseUnchangedByDryRun('tenant_migrate_tenant_b_test');
        self::assertNull($context->getTenant());

        [$tenantAStatus, $tenantAOutput] = $this->runCommand($command, ['--tenant' => 'tenant-a']);
        self::assertSame(Command::SUCCESS, $tenantAStatus, $tenantAOutput);
        $this->assertDatabaseMigrated($this->connection('tenant_migrate_tenant_a_test'), true);
        $this->assertDatabaseUnchangedByDryRun('tenant_migrate_tenant_b_test');

        [$tenantBStatus, $tenantBOutput] = $this->runCommand($command, ['--tenant' => 'tenant-b']);
        self::assertSame(Command::SUCCESS, $tenantBStatus, $tenantBOutput);
        $this->assertDatabaseMigrated($this->connection('tenant_migrate_tenant_b_test'), true);

        [$tenantASecondStatus, $tenantASecondOutput] = $this->runCommand($command, ['--tenant' => 'tenant-a']);
        self::assertSame(Command::SUCCESS, $tenantASecondStatus, $tenantASecondOutput);
        self::assertStringContainsString('No migrations to execute for tenant tenant-a', $tenantASecondOutput);
        $this->assertDatabaseMigrated($this->connection('tenant_migrate_tenant_a_test'), true);

        self::assertSame(
            ['tenant-a', 'tenant-b', 'tenant-a', 'tenant-b', 'tenant-a'],
            $provider->requestedTenants,
        );
        self::assertNull($context->getTenant());
        self::assertSame(0, $this->openConnections('tenant_migrate_tenant_a_test'));
        self::assertSame(0, $this->openConnections('tenant_migrate_tenant_b_test'));

        $defaultConnection->close();
    }

    public function testMigrationFailureStopsAtTheFailingTenantAndPreservesTheFailure(): void
    {
        $tenantA = $this->tenant(101, 'tenant-a');
        $tenantB = $this->tenant(202, 'tenant-b');
        $context = new TenantContext();
        $defaultConnection = $this->connection('tenant_migrate_shared_test');
        $command = $this->command(
            'multi_db',
            $this->configuration([Version20260903030000::class]),
            new InMemoryTenantRegistry([$tenantB, $tenantA]),
            $context,
            $this->recordingProvider(),
            $defaultConnection,
        );

        [$status, $output] = $this->runCommand($command);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('Migration failed: Controlled tenant migration failure.', $output);
        self::assertStringNotContainsString('Tenant migrations completed successfully.', $output);
        self::assertTrue($this->tableExists($this->connection('tenant_migrate_tenant_a_test'), self::METADATA_TABLE, true));
        self::assertFalse($this->tableExists($this->connection('tenant_migrate_tenant_b_test'), self::METADATA_TABLE, true));
        self::assertNull($context->getTenant());
        self::assertSame(0, $this->openConnections('tenant_migrate_tenant_a_test'));
        self::assertSame(0, $this->openConnections('tenant_migrate_tenant_b_test'));

        $defaultConnection->close();
    }

    public function testConnectionResolutionFailureStopsBeforeTheNextTenantAndRestoresContext(): void
    {
        $tenantA = $this->tenant(101, 'tenant-a');
        $tenantB = $this->tenant(202, 'tenant-b');
        $context = new TenantContext();
        $defaultConnection = $this->connection('tenant_migrate_shared_test');
        $parameters = $this->baseParameters;
        $provider = new class($parameters) implements TenantConnectionParametersProviderInterface {
            /** @param array<string, mixed> $parameters */
            public function __construct(private readonly array $parameters)
            {
            }

            public function parametersFor(TenantConnectionState $state): array
            {
                $tenant = $state->tenant ?? throw new \LogicException('A tenant is required.');
                if ('tenant-b' === $tenant->getSlug()) {
                    throw new \RuntimeException('Controlled tenant connection resolution failure.');
                }

                return array_merge($this->parameters, ['dbname' => 'tenant_migrate_tenant_a_test']);
            }
        };
        $command = $this->command(
            'multi_db',
            $this->successfulConfiguration(),
            new InMemoryTenantRegistry([$tenantB, $tenantA]),
            $context,
            $provider,
            $defaultConnection,
        );

        [$status, $output] = $this->runCommand($command);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('Controlled tenant connection resolution failure.', $output);
        $this->assertDatabaseMigrated($this->connection('tenant_migrate_tenant_a_test'), true);
        $this->assertDatabaseUnchangedByDryRun('tenant_migrate_tenant_b_test');
        self::assertNull($context->getTenant());

        $defaultConnection->close();
    }

    public function testUnknownTenantAndMissingMigrationsReturnHonestExitCodes(): void
    {
        $tenant = $this->tenant(101, 'tenant-a');
        $context = new TenantContext();
        $defaultConnection = $this->connection('tenant_migrate_shared_test');
        $command = $this->command(
            'multi_db',
            $this->configuration([]),
            new InMemoryTenantRegistry([$tenant]),
            $context,
            $this->recordingProvider(),
            $defaultConnection,
        );

        [$unknownStatus, $unknownOutput] = $this->runCommand($command, ['--tenant' => 'missing']);
        self::assertSame(Command::FAILURE, $unknownStatus, $unknownOutput);
        self::assertStringContainsString('Unknown tenant: missing', $unknownOutput);

        [$missingStatus, $missingOutput] = $this->runCommand($command, ['--tenant' => 'tenant-a']);
        self::assertSame(Command::FAILURE, $missingStatus, $missingOutput);
        self::assertStringContainsString('No migrations found for tenant tenant-a', $missingOutput);

        [$allowedStatus, $allowedOutput] = $this->runCommand($command, [
            '--tenant' => 'tenant-a',
            '--allow-no-migration' => true,
        ]);
        self::assertSame(Command::SUCCESS, $allowedStatus, $allowedOutput);
        self::assertStringContainsString('No migrations found for tenant tenant-a', $allowedOutput);
        self::assertNull($context->getTenant());

        $defaultConnection->close();
    }

    /** @return array{int, string} */
    private function runCommand(MigrateTenantsCommand $command, array $arguments = []): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->addCommand($command);
        $tester = new ApplicationTester($application);
        $status = $tester->run(
            ['command' => 'tenant:migrate', ...$arguments],
            ['interactive' => false, 'decorated' => false],
        );

        return [$status, $tester->getDisplay()];
    }

    private function command(
        string $strategy,
        Configuration $configuration,
        InMemoryTenantRegistry $registry,
        TenantContextInterface $context,
        TenantConnectionParametersProviderInterface $provider,
        Connection $defaultConnection,
    ): MigrateTenantsCommand {
        return new MigrateTenantsCommand(
            $registry,
            $context,
            $provider,
            $configuration,
            $defaultConnection,
            $strategy,
        );
    }

    private function successfulConfiguration(): Configuration
    {
        return $this->configuration([
            Version20260903010000::class,
            Version20260903020000::class,
        ]);
    }

    /** @param list<class-string> $migrationClasses */
    private function configuration(array $migrationClasses): Configuration
    {
        $configuration = new Configuration();
        foreach ($migrationClasses as $migrationClass) {
            $configuration->addMigrationClass($migrationClass);
        }

        $metadata = new TableMetadataStorageConfiguration();
        $metadata->setTableName(self::METADATA_TABLE);
        $configuration->setMetadataStorageConfiguration($metadata);
        $configuration->setAllOrNothing(true);

        return $configuration;
    }

    private function tenant(int $id, string $slug): TestTenant
    {
        return (new TestTenant())->setId($id)->setSlug($slug)->setName($slug);
    }

    private function rejectingProvider(): TenantConnectionParametersProviderInterface
    {
        return new class implements TenantConnectionParametersProviderInterface {
            public function parametersFor(TenantConnectionState $state): array
            {
                throw new \LogicException('The shared-database command must not resolve a tenant connection.');
            }
        };
    }

    private function recordingProvider(): TenantConnectionParametersProviderInterface
    {
        $parameters = $this->baseParameters;

        return new class($parameters) implements TenantConnectionParametersProviderInterface {
            /** @var list<string> */
            public array $requestedTenants = [];

            /** @param array<string, mixed> $parameters */
            public function __construct(private readonly array $parameters)
            {
            }

            public function parametersFor(TenantConnectionState $state): array
            {
                $tenant = $state->tenant ?? throw new \LogicException('A tenant is required.');
                $slug = $tenant->getSlug();
                $this->requestedTenants[] = $slug;
                $database = match ($slug) {
                    'tenant-a' => 'tenant_migrate_tenant_a_test',
                    'tenant-b' => 'tenant_migrate_tenant_b_test',
                    default => throw new \InvalidArgumentException(sprintf('No migration database for tenant "%s".', $slug)),
                };

                return array_merge($this->parameters, ['dbname' => $database]);
            }
        };
    }

    private function assertDatabaseUnchangedByDryRun(string $databaseName): void
    {
        $connection = $this->connection($databaseName);
        self::assertFalse($this->tableExists($connection, 'tenant_migration_probe'));
        self::assertFalse($this->tableExists($connection, self::METADATA_TABLE));
        self::assertSame(0, $connection->getTransactionNestingLevel());
        $connection->close();
    }

    private function assertDatabaseMigrated(Connection $connection, bool $close = false): void
    {
        self::assertTrue($this->tableExists($connection, 'tenant_migration_probe'));
        self::assertTrue($this->tableExists($connection, self::METADATA_TABLE));
        self::assertSame(['first', 'second'], $connection->fetchFirstColumn('SELECT marker FROM tenant_migration_probe ORDER BY sequence'));
        self::assertSame(2, (int) $connection->fetchOne("SELECT applied_order FROM tenant_migration_probe WHERE marker = 'second'"));
        self::assertSame([
            Version20260903010000::class,
            Version20260903020000::class,
        ], $connection->fetchFirstColumn(sprintf('SELECT version FROM %s ORDER BY version', self::METADATA_TABLE)));
        self::assertSame(0, $connection->getTransactionNestingLevel());

        if ($close) {
            $connection->close();
        }
    }

    private function tableExists(Connection $connection, string $table, bool $close = false): bool
    {
        $exists = (bool) $connection->fetchOne('SELECT to_regclass(?) IS NOT NULL', [$table]);

        if ($close) {
            $connection->close();
        }

        return $exists;
    }

    private function openConnections(string $databaseName): int
    {
        return (int) $this->adminConnection->fetchOne(
            'SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$databaseName],
        );
    }

    private function resetDatabase(string $databaseName): void
    {
        $connection = $this->connection($databaseName);
        $connection->executeStatement('DROP TABLE IF EXISTS tenant_migration_probe');
        $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::METADATA_TABLE));
        $connection->close();
    }

    private function connection(string $databaseName): Connection
    {
        return DriverManager::getConnection(array_merge($this->baseParameters, ['dbname' => $databaseName]));
    }

    /** @return list<string> */
    private function databaseNames(): array
    {
        return [
            'tenant_migrate_shared_test',
            'tenant_migrate_tenant_a_test',
            'tenant_migrate_tenant_b_test',
        ];
    }

    private function environment(string $name, string $default): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
