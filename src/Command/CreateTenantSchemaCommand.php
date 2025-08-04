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
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to create database schema for tenants.
 *
 * This command creates the database schema for all tenants or a specific tenant
 * when using separate database strategy.
 */
#[AsCommand(
    name: 'tenant:schema:create',
    description: 'Create database schema for tenants'
)]
class CreateTenantSchemaCommand extends Command
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
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Create schema for a specific tenant slug')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dumps the generated SQL statements to the screen (does not execute them)')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command creates database schema for tenants:

    <info>%command.full_name%</info>

You can optionally specify a tenant to create schema for:

    <info>%command.full_name% --tenant=acme</info>

You can also dump the SQL instead of executing it:

    <info>%command.full_name% --dump-sql</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantSlug = $input->getOption('tenant');
        $dumpSql = $input->getOption('dump-sql');

        try {
            $tenants = ($tenantSlug !== null && is_string($tenantSlug))
                ? [$this->tenantRegistry->getBySlug($tenantSlug)]
                : $this->tenantRegistry->getAll();

            if (empty($tenants)) {
                $io->warning('No tenants found to create schema for.');
                return Command::SUCCESS;
            }

            $io->title('Tenant Schema Creation');

            // Get all entity metadata
            $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();

            if (empty($metadatas)) {
                $io->error('No entity metadata found.');
                return Command::FAILURE;
            }

            foreach ($tenants as $tenant) {
                $io->section(sprintf('Creating schema for tenant: %s', $tenant->getSlug()));

                // Set tenant context
                $this->tenantContext->setTenant($tenant);

                // Switch to tenant connection
                $this->connectionResolver->switchToTenantConnection($tenant);

                // Create tenant-specific entity manager
                $tenantEntityManager = $this->entityManagerFactory->createForTenant($tenant);

                // Create schema tool
                $schemaTool = new SchemaTool($tenantEntityManager);

                if ($dumpSql) {
                    $sqls = $schemaTool->getCreateSchemaSql($metadatas);
                    if (!empty($sqls)) {
                        $io->text(sprintf('SQL for tenant %s:', $tenant->getSlug()));
                        $io->block($sqls);
                    } else {
                        $io->note(sprintf('No SQL to execute for tenant %s', $tenant->getSlug()));
                    }
                } else {
                    $schemaTool->createSchema($metadatas);
                    $io->success(sprintf('Successfully created schema for tenant %s', $tenant->getSlug()));
                }

                // Close the tenant entity manager
                $tenantEntityManager->close();
            }

            if ($dumpSql) {
                $io->success('Schema SQL dumped successfully.');
            } else {
                $io->success('Tenant schema creation completed successfully.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Schema creation failed: %s', $e->getMessage()));
            return Command::FAILURE;
        } finally {
            // Clear tenant context
            $this->tenantContext->clear();
        }
    }
}