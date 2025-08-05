<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to drop database schema for tenants.
 *
 * This command drops the database schema for all tenants or a specific tenant
 * when using separate database strategy.
 */
#[AsCommand(
    name: 'tenant:schema:drop',
    description: 'Drop database schema for tenants'
)]
class DropTenantSchemaCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryInterface $tenantRegistry,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantConnectionResolverInterface $connectionResolver,
        private readonly TenantEntityManagerFactory $entityManagerFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Drop schema for a specific tenant slug')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dumps the generated SQL statements to the screen (does not execute them)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the operation to run when in production')
            ->addOption('full-database', null, InputOption::VALUE_NONE, 'Drop the entire database instead of just the schema')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command drops database schema for tenants:

    <info>%command.full_name%</info>

You can optionally specify a tenant to drop schema for:

    <info>%command.full_name% --tenant=acme</info>

You can also dump the SQL instead of executing it:

    <info>%command.full_name% --dump-sql</info>

<error>CAUTION: This operation should not be executed in a production environment.</error>

<error>All data will be lost!</error>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantSlug = $input->getOption('tenant');
        $dumpSql = $input->getOption('dump-sql');
        $force = $input->getOption('force');
        $fullDatabase = $input->getOption('full-database');

        try {
            $tenants = (is_string($tenantSlug))
                ? [$this->tenantRegistry->getBySlug($tenantSlug)]
                : $this->tenantRegistry->getAll();

            if (empty($tenants)) {
                $io->warning('No tenants found to drop schema for.');

                return Command::SUCCESS;
            }

            // Safety check for production
            if (!$force && !$dumpSql) {
                $io->caution('This operation should not be executed in a production environment.');
                $io->warning('All data will be lost!');

                $question = new ConfirmationQuestion(
                    'Are you sure you wish to continue? (yes/no)',
                    false
                );

                if (!$io->askQuestion($question)) {
                    $io->note('Operation cancelled.');

                    return Command::SUCCESS;
                }
            }

            $io->title('Tenant Schema Drop');

            // Get all entity metadata
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

            foreach ($tenants as $tenant) {
                $io->section(sprintf('Dropping schema for tenant: %s', $tenant->getSlug()));

                // Set tenant context
                $this->tenantContext->setTenant($tenant);

                // Switch to tenant connection
                $this->connectionResolver->switchToTenantConnection($tenant);

                // Create tenant-specific entity manager
                $tenantEntityManager = $this->entityManagerFactory->createForTenant($tenant);

                // Create schema tool
                $schemaTool = new SchemaTool($tenantEntityManager);

                if ($dumpSql) {
                    $sql = $fullDatabase
                        ? $schemaTool->getDropDatabaseSQL()
                        : $schemaTool->getDropSchemaSQL($metadata);

                    if (!empty($sql)) {
                        $io->text(sprintf('SQL for tenant %s:', $tenant->getSlug()));
                        $io->block($sql);
                    } else {
                        $io->note(sprintf('No SQL to execute for tenant %s', $tenant->getSlug()));
                    }
                } elseif ($fullDatabase) {
                    $schemaTool->dropDatabase();
                    $io->success(sprintf('Successfully dropped database for tenant %s', $tenant->getSlug()));
                } else {
                    $schemaTool->dropSchema($metadata);
                    $io->success(sprintf('Successfully dropped schema for tenant %s', $tenant->getSlug()));
                }

                // Close the tenant entity manager
                $tenantEntityManager->close();
            }

            if ($dumpSql) {
                $io->success('Schema drop SQL dumped successfully.');
            } else {
                $io->success('Tenant schema drop completed successfully.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Schema drop failed: %s', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            // Clear tenant context
            $this->tenantContext->clear();
        }
    }
}
