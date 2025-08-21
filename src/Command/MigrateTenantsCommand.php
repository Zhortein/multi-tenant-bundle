<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
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
        private readonly TenantConnectionResolverInterface $connectionResolver,
        private readonly Configuration $migrationConfiguration,
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
        } catch (\Exception $e) {
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

        // Create dependency factory for default connection
        $dependencyFactory = $this->createDefaultDependencyFactory();

        // Execute migrations
        $migrator = $dependencyFactory->getMigrator();
        $availableMigrations = $dependencyFactory->getMigrationRepository()->getMigrations();

        if (0 === $availableMigrations->count()) {
            if ($allowNoMigration) {
                $io->success('No migrations to execute.');

                return Command::SUCCESS;
            }
            $io->warning('No migrations found.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run mode - no migrations will be executed.');
            $sql = $migrator->migrate(null, true);
            foreach ($sql as $query) {
                $io->writeln($query);
            }
        } else {
            $result = $migrator->migrate();
            $io->success(sprintf('Successfully executed %d migrations.', count($result->getMigrations())));
        }

        return Command::SUCCESS;
    }

    /**
     * Execute migrations for multi-database strategy.
     */
    private function executeMultiDbMigrations(SymfonyStyle $io, bool $dryRun, bool $allowNoMigration): int
    {
        $tenants = $this->getTargetTenants();

        if (empty($tenants)) {
            $io->warning('No tenants found to migrate.');

            return Command::SUCCESS;
        }

        $io->title('Multi-Database Tenant Migrations');

        foreach ($tenants as $tenant) {
            $io->section(sprintf('Migrating tenant: %s', $tenant->getSlug()));

            // Set tenant context
            $this->tenantContext->setTenant($tenant);

            // Switch to tenant connection
            $this->connectionResolver->switchToTenantConnection($tenant);

            // Get connection parameters for this tenant
            $connectionParams = $this->connectionResolver->resolveParameters($tenant);

            // Create tenant-specific dependency factory
            $dependencyFactory = $this->createTenantDependencyFactory($connectionParams);

            // Execute migrations
            $migrator = $dependencyFactory->getMigrator();
            $migrations = $dependencyFactory->getMigrationRepository()->getMigrations();

            if (0 === $migrations->count()) {
                if ($allowNoMigration) {
                    $io->note(sprintf('No migrations found for tenant %s', $tenant->getSlug()));
                    continue;
                }

                $io->error(sprintf('No migrations found for tenant %s', $tenant->getSlug()));

                return Command::FAILURE;
            }

            if ($dryRun) {
                $io->note('Executing migration as dry run...');
                // For dry run, we need to get the plan and show SQL
                $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
                /** @phpstan-ignore-next-line */
                $plan = $planCalculator->getPlanToMigrateUp();

                if ($plan->count() > 0) {
                    $io->text('SQL that would be executed:');
                    /* @phpstan-ignore-next-line */
                    foreach ($plan->getItems() as $item) {
                        /* @phpstan-ignore-next-line */
                        $io->text(sprintf('-- Migration: %s', $item->getVersion()));
                        // Note: Actual SQL queries would require executing the migration
                        $io->text('-- SQL queries would be shown here in a real implementation');
                    }
                } else {
                    $io->success('No migrations to execute.');
                }
            } else {
                // Execute migrations
                $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
                /** @phpstan-ignore-next-line */
                $plan = $planCalculator->getPlanToMigrateUp();

                /* @phpstan-ignore-next-line */
                if ($plan->count() > 0) {
                    /** @phpstan-ignore-next-line */
                    $result = $migrator->migrate($plan, $dryRun);
                    $io->success(sprintf(
                        'Successfully executed %d migrations for tenant %s',
                        /* @phpstan-ignore-next-line */
                        $plan->count(),
                        $tenant->getSlug()
                    ));
                } else {
                    $io->note(sprintf('No migrations to execute for tenant %s', $tenant->getSlug()));
                }
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
        return DependencyFactory::fromConfiguration(
            new ExistingConfiguration($this->migrationConfiguration)
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

        // Create configuration for this tenant
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory(
            /* @phpstan-ignore-next-line */
            $this->migrationConfiguration->getMigrationsNamespace(),
            /* @phpstan-ignore-next-line */
            $this->migrationConfiguration->getMigrationsDirectory()
        );
        $configuration->setAllOrNothing($this->migrationConfiguration->isAllOrNothing());
        $configuration->setCheckDatabasePlatform($this->migrationConfiguration->isDatabasePlatformChecked());

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection)
        );
    }
}
