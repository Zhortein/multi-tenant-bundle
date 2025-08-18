<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

/**
 * Console command to synchronize PostgreSQL Row-Level Security (RLS) policies.
 *
 * This command scans Doctrine metadata for entities marked with the AsTenantAware
 * attribute and generates/applies RLS policies for tenant isolation.
 */
#[AsCommand(
    name: 'tenant:rls:sync',
    description: 'Synchronize PostgreSQL Row-Level Security policies for tenant-aware entities'
)]
final class SyncRlsPoliciesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly string $sessionVariable = 'app.tenant_id',
        private readonly string $policyNamePrefix = 'tenant_isolation',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Apply the generated SQL statements to the database'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force recreation of existing policies'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command scans for tenant-aware entities and generates
PostgreSQL Row-Level Security (RLS) policies for defense-in-depth protection.

By default, it only displays the SQL that would be executed:
  <info>php %command.full_name%</info>

To actually apply the changes to the database:
  <info>php %command.full_name% --apply</info>

To force recreation of existing policies:
  <info>php %command.full_name% --apply --force</info>

This command only works with PostgreSQL databases and requires the database
strategy to be 'shared_db' or 'hybrid'.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = $input->getOption('apply');
        $force = $input->getOption('force');

        // Check if we're using PostgreSQL
        if (!$this->isPostgreSQL()) {
            $io->error('RLS policies are only supported with PostgreSQL databases.');

            return Command::FAILURE;
        }

        $io->title('PostgreSQL Row-Level Security (RLS) Policy Synchronization');

        try {
            $tenantAwareEntities = $this->findTenantAwareEntities();

            if (empty($tenantAwareEntities)) {
                $io->success('No tenant-aware entities found. Nothing to do.');

                return Command::SUCCESS;
            }

            $io->section(sprintf('Found %d tenant-aware entities', count($tenantAwareEntities)));

            $sqlStatements = [];

            foreach ($tenantAwareEntities as $entityClass => $metadata) {
                $tableName = $metadata->getTableName();
                $io->text(sprintf('Processing entity: <info>%s</info> (table: <comment>%s</comment>)', $entityClass, $tableName));

                $statements = $this->generateRlsStatements($tableName, $force);
                $sqlStatements = array_merge($sqlStatements, $statements);
            }

            if (empty($sqlStatements)) {
                $io->success('All RLS policies are already up to date.');

                return Command::SUCCESS;
            }

            $io->section('Generated SQL statements:');
            foreach ($sqlStatements as $sql) {
                $io->text('<comment>'.$sql.'</comment>');
            }

            if ($apply) {
                $io->section('Applying SQL statements...');

                foreach ($sqlStatements as $sql) {
                    try {
                        $this->connection->executeStatement($sql);
                        $io->text('✓ '.$sql);
                    } catch (Exception $exception) {
                        $io->error(sprintf('Failed to execute: %s', $sql));
                        $io->error($exception->getMessage());

                        return Command::FAILURE;
                    }
                }

                $io->success(sprintf('Successfully applied %d SQL statements.', count($sqlStatements)));
            } else {
                $io->note('Use --apply option to execute these statements.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error(sprintf('An error occurred: %s', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Finds all entities marked with the AsTenantAware attribute.
     *
     * @return array<string, ClassMetadata<object>> Array of entity class names to metadata
     */
    private function findTenantAwareEntities(): array
    {
        $tenantAwareEntities = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $reflectionClass = $metadata->getReflectionClass();

            if (null === $reflectionClass) {
                continue;
            }

            $attributes = $reflectionClass->getAttributes(AsTenantAware::class);

            if (!empty($attributes)) {
                /** @var AsTenantAware $attribute */
                $attribute = $attributes[0]->newInstance();

                // Only include entities that require tenant_id (shared_db mode)
                if ($attribute->requireTenantId) {
                    $tenantAwareEntities[$metadata->getName()] = $metadata;
                }
            }
        }

        return $tenantAwareEntities;
    }

    /**
     * Generates RLS statements for a table.
     *
     * @param string $tableName The table name
     * @param bool   $force     Whether to force recreation of existing policies
     *
     * @return array<string> Array of SQL statements
     */
    private function generateRlsStatements(string $tableName, bool $force): array
    {
        $statements = [];
        $policyName = $this->policyNamePrefix.'_'.$tableName;

        try {
            // Check if RLS is already enabled on the table
            $rlsEnabled = $this->isRlsEnabled($tableName);

            if (!$rlsEnabled) {
                $statements[] = sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY;', $this->connection->quoteIdentifier($tableName));
            }

            // Check if policy already exists
            $policyExists = $this->policyExists($tableName, $policyName);

            if ($policyExists && $force) {
                $statements[] = sprintf(
                    'DROP POLICY IF EXISTS %s ON %s;',
                    $this->connection->quoteIdentifier($policyName),
                    $this->connection->quoteIdentifier($tableName)
                );
                $policyExists = false;
            }

            if (!$policyExists) {
                $statements[] = sprintf(
                    'CREATE POLICY %s ON %s USING (tenant_id::text = current_setting(%s, true));',
                    $this->connection->quoteIdentifier($policyName),
                    $this->connection->quoteIdentifier($tableName),
                    $this->connection->quote($this->sessionVariable)
                );
            }
        } catch (Exception $exception) {
            // If we can't check existing state, generate all statements
            $statements[] = sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY;', $this->connection->quoteIdentifier($tableName));
            $statements[] = sprintf(
                'CREATE POLICY %s ON %s USING (tenant_id::text = current_setting(%s, true));',
                $this->connection->quoteIdentifier($policyName),
                $this->connection->quoteIdentifier($tableName),
                $this->connection->quote($this->sessionVariable)
            );
        }

        return $statements;
    }

    /**
     * Checks if RLS is enabled on a table.
     */
    private function isRlsEnabled(string $tableName): bool
    {
        try {
            $result = $this->connection->fetchOne(
                'SELECT relrowsecurity FROM pg_class WHERE relname = ?',
                [$tableName]
            );

            return (bool) $result;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Checks if a policy exists on a table.
     */
    private function policyExists(string $tableName, string $policyName): bool
    {
        try {
            $result = $this->connection->fetchOne(
                'SELECT 1 FROM pg_policies WHERE tablename = ? AND policyname = ?',
                [$tableName, $policyName]
            );

            return false !== $result;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Checks if the current database connection is PostgreSQL.
     */
    private function isPostgreSQL(): bool
    {
        return str_contains($this->connection->getDatabasePlatform()->getName(), 'postgresql');
    }
}
