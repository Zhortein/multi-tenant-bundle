<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Query\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to execute Doctrine migrations for tenants.
 *
 * This command supports both database strategies:
 * - shared_db: Runs migrations once on the shared database
 * - multi_db: Runs migrations on each tenant's separate database
 *
 * Supports global --tenant option for per-tenant migrations.
 */
#[AsCommand(
    name: 'tenant:migrate',
    description: 'Execute Doctrine migrations for tenants'
)]
class MigrateTenantsCommand extends AbstractTenantAwareCommand
{
    public function __construct(
        TenantRegistryInterface $tenantRegistry,
        TenantContextInterface $tenantContext,
        private readonly TenantConnectionParametersProviderInterface $connectionParametersProvider,
        private readonly Configuration $migrationConfiguration,
        private readonly Connection $defaultConnection,
        private readonly string $databaseStrategy = 'shared_db',
    ) {
        parent::__construct($tenantRegistry, $tenantContext);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Execute the migration as a dry run')
            ->addOption('allow-no-migration', null, InputOption::VALUE_NONE, 'Don\'t throw an exception if no migration is available')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command executes Doctrine migrations for tenants:

    <info>%command.full_name%</info>

You can optionally specify a tenant to migrate:

    <info>%command.full_name% --tenant=acme</info>

You can also execute the migration as a dry run:

    <info>%command.full_name% --dry-run</info>

The tenant can also be specified via TENANT_ID environment variable:

    <info>TENANT_ID=acme %command.full_name%</info>
EOT
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $allowNoMigration = $input->getOption('allow-no-migration');

        // Show current tenant context if set
        $currentTenant = $this->getCurrentTenant();
        if (null !== $currentTenant) {
            $this->displayTenantInfo($io, $currentTenant);
        }

        try {
            if ('shared_db' === $this->databaseStrategy) {
                return $this->executeSharedDbMigrations($io, $dryRun, $allowNoMigration);
            }

            return $this->executeMultiDbMigrations($io, $dryRun, $allowNoMigration);
        } catch (\Throwable $e) {
            $io->error(sprintf('Migration failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Execute migrations for shared database strategy.
     */
    private function executeSharedDbMigrations(SymfonyStyle $io, bool $dryRun, bool $allowNoMigration): int
    {
        $io->title('Shared Database Migrations');
        $io->note('Running migrations on shared database with tenant filtering.');

        $dependencyFactory = $this->createDefaultDependencyFactory();
        $migrationFailure = null;

        try {
            $availableMigrations = $dependencyFactory->getMigrationRepository()->getMigrations();

            if (0 === $availableMigrations->count()) {
                if ($allowNoMigration) {
                    $io->success('No migrations to execute.');

                    return Command::SUCCESS;
                }
                $io->warning('No migrations found.');

                return Command::SUCCESS;
            }

            $plan = $this->createMigrationPlan($dependencyFactory, $dryRun);
            if (0 === $plan->count()) {
                $io->success('No migrations to execute.');

                return Command::SUCCESS;
            }

            $sql = $this->executeMigrationPlan($dependencyFactory, $plan, $dryRun, $allowNoMigration);

            if ($dryRun) {
                $io->note('Dry run mode - no migrations were executed.');
                $this->writeSql($io, $sql);
                $io->success(sprintf('Dry run completed for %d migrations.', $plan->count()));
            } else {
                $io->success(sprintf('Successfully executed %d migrations.', $plan->count()));
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $migrationFailure = $exception;

            throw $exception;
        } finally {
            $this->closeDependencyFactory($dependencyFactory, $migrationFailure, $io);
        }
    }

    /**
     * Execute migrations for multi-database strategy.
     */
    private function executeMultiDbMigrations(SymfonyStyle $io, bool $dryRun, bool $allowNoMigration): int
    {
        $tenants = $this->getTargetTenants();
        usort($tenants, static fn (TenantInterface $left, TenantInterface $right): int => [$left->getSlug(), (string) $left->getId()] <=> [$right->getSlug(), (string) $right->getId()]);

        if (empty($tenants)) {
            $io->warning('No tenants found to migrate.');

            return Command::SUCCESS;
        }

        $io->title('Multi-Database Tenant Migrations');

        foreach ($tenants as $tenant) {
            $io->section(sprintf('Migrating tenant: %s', $tenant->getSlug()));
            $dependencyFactory = null;
            $migrationFailure = null;

            try {
                $this->tenantContext->setTenant($tenant);

                $connectionParams = $this->connectionParametersProvider->parametersFor(TenantConnectionState::tenant($tenant));
                $dependencyFactory = $this->createTenantDependencyFactory($connectionParams);
                $migrations = $dependencyFactory->getMigrationRepository()->getMigrations();

                if (0 === $migrations->count()) {
                    if ($allowNoMigration) {
                        $io->note(sprintf('No migrations found for tenant %s', $tenant->getSlug()));
                        continue;
                    }

                    $io->error(sprintf('No migrations found for tenant %s', $tenant->getSlug()));

                    return Command::FAILURE;
                }

                $plan = $this->createMigrationPlan($dependencyFactory, $dryRun);

                if (0 === $plan->count()) {
                    $io->note(sprintf('No migrations to execute for tenant %s', $tenant->getSlug()));

                    continue;
                }

                $sql = $this->executeMigrationPlan($dependencyFactory, $plan, $dryRun, $allowNoMigration);

                if ($dryRun) {
                    $io->note('Dry run mode - no migrations were executed.');
                    $this->writeSql($io, $sql);
                    $io->success(sprintf(
                        'Dry run completed for %d migrations for tenant %s',
                        $plan->count(),
                        $tenant->getSlug()
                    ));

                    continue;
                }

                $io->success(sprintf(
                    'Successfully executed %d migrations for tenant %s',
                    $plan->count(),
                    $tenant->getSlug()
                ));
            } catch (\Throwable $exception) {
                $migrationFailure = $exception;

                throw $exception;
            } finally {
                $this->cleanupTenant($dependencyFactory, $migrationFailure, $io);
            }
        }

        $io->success('Tenant migrations completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * Creates a default dependency factory for shared database migrations.
     */
    private function createDefaultDependencyFactory(): DependencyFactory
    {
        $dbalConfiguration = clone $this->defaultConnection->getConfiguration();
        $dbalConfiguration->setSchemaAssetsFilter(static fn (): bool => true);
        /** @phpstan-ignore-next-line */
        $connection = DriverManager::getConnection($this->defaultConnection->getParams(), $dbalConfiguration);

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($this->migrationConfiguration),
            new ExistingConnection($connection),
        );
    }

    /**
     * Creates a tenant-specific dependency factory for migrations.
     *
     * @param array<string, mixed> $connectionParams
     */
    private function createTenantDependencyFactory(array $connectionParams): DependencyFactory
    {
        // Create connection for this tenant
        /** @phpstan-ignore-next-line */
        $connection = DriverManager::getConnection($connectionParams);

        $configuration = $this->copyMigrationConfigurationForTenant();

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection)
        );
    }

    private function copyMigrationConfigurationForTenant(): Configuration
    {
        $configuration = new Configuration();

        foreach ($this->migrationConfiguration->getMigrationDirectories() as $namespace => $path) {
            $configuration->addMigrationsDirectory($namespace, $path);
        }

        foreach ($this->migrationConfiguration->getMigrationClasses() as $migrationClass) {
            $configuration->addMigrationClass($migrationClass);
        }

        $metadataStorageConfiguration = $this->migrationConfiguration->getMetadataStorageConfiguration();
        if (null !== $metadataStorageConfiguration) {
            $configuration->setMetadataStorageConfiguration($metadataStorageConfiguration);
        }

        if ($this->migrationConfiguration->areMigrationsOrganizedByYearAndMonth()) {
            $configuration->setMigrationsAreOrganizedByYearAndMonth();
        } elseif ($this->migrationConfiguration->areMigrationsOrganizedByYear()) {
            $configuration->setMigrationsAreOrganizedByYear();
        }

        $configuration->setCustomTemplate($this->migrationConfiguration->getCustomTemplate());
        $configuration->setAllOrNothing($this->migrationConfiguration->isAllOrNothing());
        $configuration->setTransactional($this->migrationConfiguration->isTransactional());
        $configuration->setCheckDatabasePlatform($this->migrationConfiguration->isDatabasePlatformChecked());

        return $configuration;
    }

    private function createMigrationPlan(DependencyFactory $dependencyFactory, bool $dryRun): MigrationPlanList
    {
        if (!$dryRun) {
            $dependencyFactory->getMetadataStorage()->ensureInitialized();
        }

        $target = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');

        return $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($target);
    }

    /**
     * @return array<string, Query[]>
     */
    private function executeMigrationPlan(
        DependencyFactory $dependencyFactory,
        MigrationPlanList $plan,
        bool $dryRun,
        bool $allowNoMigration,
    ): array {
        $migratorConfiguration = (new MigratorConfiguration())
            ->setDryRun($dryRun)
            ->setAllOrNothing($dependencyFactory->getConfiguration()->isAllOrNothing())
            ->setNoMigrationException($allowNoMigration);

        return $dependencyFactory->getMigrator()->migrate($plan, $migratorConfiguration);
    }

    /**
     * @param array<string, Query[]> $sql
     */
    private function writeSql(SymfonyStyle $io, array $sql): void
    {
        $io->text('SQL that would be executed:');

        foreach ($sql as $version => $queries) {
            $io->text(sprintf('-- Migration: %s', $version));
            foreach ($queries as $query) {
                $io->writeln($query->getStatement());
            }
        }
    }

    private function cleanupTenant(
        ?DependencyFactory $dependencyFactory,
        ?\Throwable $migrationFailure,
        SymfonyStyle $io,
    ): void {
        $cleanupFailure = null;

        if (null !== $dependencyFactory) {
            try {
                $this->closeDependencyFactory($dependencyFactory, $migrationFailure, $io);
            } catch (\Throwable $exception) {
                $cleanupFailure = $exception;
            }
        }

        try {
            $this->tenantContext->clear();
        } catch (\Throwable $exception) {
            $cleanupFailure ??= $exception;
        }

        if (null === $cleanupFailure) {
            return;
        }

        if (null !== $migrationFailure) {
            $io->warning('Tenant migration cleanup also failed; the original migration failure was preserved.');

            return;
        }

        throw $cleanupFailure;
    }

    private function closeDependencyFactory(
        DependencyFactory $dependencyFactory,
        ?\Throwable $migrationFailure,
        SymfonyStyle $io,
    ): void {
        try {
            $dependencyFactory->getConnection()->close();
        } catch (\Throwable $cleanupFailure) {
            if (null !== $migrationFailure) {
                $io->warning('Tenant migration connection cleanup also failed; the original migration failure was preserved.');

                return;
            }

            throw $cleanupFailure;
        }
    }
}
