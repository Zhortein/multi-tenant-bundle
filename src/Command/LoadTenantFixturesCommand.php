<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

// Optional fixtures bundle classes - only available if doctrine/doctrine-fixtures-bundle is installed
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to load Doctrine fixtures for tenants.
 *
 * This command allows loading fixtures for all tenants or a specific tenant
 * when using separate database strategy.
 *
 * Supports global --tenant option for per-tenant fixture loading.
 */
#[AsCommand(
    name: 'tenant:fixtures',
    description: 'Load Doctrine fixtures for tenants'
)]
class LoadTenantFixturesCommand extends AbstractTenantAwareCommand
{
    public function __construct(
        TenantRegistryInterface $tenantRegistry,
        TenantContextInterface $tenantContext,
        private readonly TenantConnectionResolverInterface $connectionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?object $fixturesLoader = null, // SymfonyFixturesLoader when available
    ) {
        parent::__construct($tenantRegistry, $tenantContext);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('append', null, InputOption::VALUE_NONE, 'Append the data fixtures instead of deleting all data from the database first')
            ->addOption('group', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only load fixtures that belong to this group')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command')
            ->addOption('purge-exclusions', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'List of database tables to ignore while purging')
            ->addOption('purge-with-truncate', null, InputOption::VALUE_NONE, 'Purge data by using a database-level TRUNCATE statement')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command loads data fixtures for tenants:

    <info>%command.full_name%</info>

You can optionally specify a tenant to load fixtures for:

    <info>%command.full_name% --tenant=acme</info>

You can also optionally append the fixtures instead of deleting all data first:

    <info>%command.full_name% --append</info>

You can also optionally specify groups of fixtures to load:

    <info>%command.full_name% --group=tenant --group=demo</info>

The tenant can also be specified via TENANT_ID environment variable:

    <info>TENANT_ID=acme %command.full_name%</info>
EOT
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $append = $input->getOption('append');
        $groups = $input->getOption('group');
        $purgeExclusions = $input->getOption('purge-exclusions');
        $purgeWithTruncate = $input->getOption('purge-with-truncate');

        // Show current tenant context if set
        $currentTenant = $this->getCurrentTenant();
        if (null !== $currentTenant) {
            $this->displayTenantInfo($io, $currentTenant);
        }

        try {
            if (null === $this->fixturesLoader) {
                $io->error('Fixtures loader is not available. Please install doctrine/doctrine-fixtures-bundle.');

                return Command::FAILURE;
            }

            $tenants = $this->getTargetTenants();

            if (empty($tenants)) {
                $io->warning('No tenants found to load fixtures for.');

                return Command::SUCCESS;
            }

            // Load fixtures - using dynamic method call since class might not exist
            /** @phpstan-ignore-next-line */
            $fixtures = $this->fixturesLoader->getFixtures($groups);

            if (empty($fixtures)) {
                $io->error('No fixtures found to load.');

                return Command::FAILURE;
            }

            $io->title('Tenant Fixtures Loading');

            foreach ($tenants as $tenant) {
                $io->section(sprintf('Loading fixtures for tenant: %s', $tenant->getSlug()));

                // Set tenant context
                $this->tenantContext->setTenant($tenant);

                // Switch to tenant connection
                $this->connectionResolver->switchToTenantConnection($tenant);

                // Confirm before purging data (unless appending)
                if (!$append && !$this->confirmPurge($io, $tenant->getSlug())) {
                    $io->note(sprintf('Skipping fixtures for tenant %s', $tenant->getSlug()));
                    continue;
                }

                // Create purger - using dynamic class instantiation
                $purgerClass = 'Doctrine\\Common\\DataFixtures\\Purger\\ORMPurger';
                if (!class_exists($purgerClass)) {
                    $io->error('ORMPurger class not found. Please install doctrine/doctrine-fixtures-bundle.');

                    return Command::FAILURE;
                }

                /** @phpstan-ignore-next-line */
                $purger = new $purgerClass($this->entityManager);
                $purgeMode = $purgeWithTruncate ? 2 : 1; // PURGE_MODE_TRUNCATE : PURGE_MODE_DELETE
                /* @phpstan-ignore-next-line */
                $purger->setPurgeMode($purgeMode);

                if (!empty($purgeExclusions)) {
                    /* @phpstan-ignore-next-line */
                    $purger->setExclusions($purgeExclusions);
                }

                // Create executor
                $executorClass = 'Doctrine\\Common\\DataFixtures\\Executor\\ORMExecutor';
                if (!class_exists($executorClass)) {
                    $io->error('ORMExecutor class not found. Please install doctrine/doctrine-fixtures-bundle.');

                    return Command::FAILURE;
                }
                /** @phpstan-ignore-next-line */
                $executor = new $executorClass($this->entityManager, $purger);

                // Execute fixtures
                /* @phpstan-ignore-next-line */
                $executor->execute($fixtures, $append);

                $io->success(sprintf(
                    'Successfully loaded %d fixtures for tenant %s',
                    /* @phpstan-ignore-next-line */
                    count($fixtures),
                    $tenant->getSlug()
                ));
            }

            $io->success('Tenant fixtures loading completed successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Fixtures loading failed: %s', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            // Clear tenant context
            $this->tenantContext->clear();
        }
    }

    /**
     * Confirms whether to purge data for a tenant.
     */
    private function confirmPurge(SymfonyStyle $io, string $tenantSlug): bool
    {
        $question = new ConfirmationQuestion(
            sprintf(
                'Careful, database "%s" will be purged. Do you want to continue? (yes/no)',
                $tenantSlug
            ),
            false
        );

        $result = $io->askQuestion($question);

        return is_bool($result) ? $result : false;
    }
}
