<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to list all tenants in the system.
 *
 * Supports filtering by current tenant context when --tenant option is used.
 */
#[AsCommand(
    name: 'tenant:list',
    description: 'Lists all tenants in the system'
)]
final class ListTenantsCommand extends AbstractTenantAwareCommand
{
    public function __construct(
        TenantRegistryInterface $tenantRegistry,
        TenantContextInterface $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
    ) {
        parent::__construct($tenantRegistry, $tenantContext);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed tenant information')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (table, json, yaml)', 'table')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command lists all tenants in the system:

    <info>%command.full_name%</info>

You can show detailed information:

    <info>%command.full_name% --detailed</info>

You can filter to a specific tenant:

    <info>%command.full_name% --tenant=acme</info>

You can change the output format:

    <info>%command.full_name% --format=json</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get tenants based on context (all or specific tenant)
        $tenants = $this->getTargetTenants();

        if (empty($tenants)) {
            $io->warning('No tenants found.');

            return Command::SUCCESS;
        }

        $format = $input->getOption('format');
        $detailed = $input->getOption('detailed');

        // Show current tenant context if set
        $currentTenant = $this->getCurrentTenant();
        if (null !== $currentTenant) {
            $this->displayTenantInfo($io, $currentTenant);
        }

        switch ($format) {
            case 'json':
                return $this->outputJson($output, $tenants, $detailed);
            case 'yaml':
                return $this->outputYaml($output, $tenants, $detailed);
            default:
                return $this->outputTable($io, $tenants, $detailed);
        }
    }

    /**
     * Outputs tenants as a table.
     *
     * @param TenantInterface[] $tenants
     */
    private function outputTable(SymfonyStyle $io, array $tenants, bool $detailed): int
    {
        $headers = ['ID', 'Slug'];
        $rows = [];

        if ($detailed) {
            $headers = array_merge($headers, ['Name', 'Mailer DSN', 'Messenger DSN']);
        }

        foreach ($tenants as $tenant) {
            $row = [
                $tenant->getId(),
                $tenant->getSlug(),
            ];

            if ($detailed) {
                $row = array_merge($row, [
                    method_exists($tenant, 'getName') ? ($tenant->getName() ?? 'N/A') : 'N/A',
                    $this->maskSensitiveData($tenant->getMailerDsn() ?? 'N/A'),
                    $this->maskSensitiveData($tenant->getMessengerDsn() ?? 'N/A'),
                ]);
            }

            $rows[] = $row;
        }

        $io->table($headers, $rows);
        $io->success(sprintf('Found %d tenant(s).', count($tenants)));

        return Command::SUCCESS;
    }

    /**
     * Outputs tenants as JSON.
     *
     * @param TenantInterface[] $tenants
     */
    private function outputJson(OutputInterface $output, array $tenants, bool $detailed): int
    {
        $data = [];

        foreach ($tenants as $tenant) {
            $tenantData = [
                'id' => $tenant->getId(),
                'slug' => $tenant->getSlug(),
            ];

            if ($detailed) {
                $tenantData['name'] = method_exists($tenant, 'getName') ? $tenant->getName() : null;
                $tenantData['mailer_dsn'] = $tenant->getMailerDsn();
                $tenantData['messenger_dsn'] = $tenant->getMessengerDsn();
            }

            $data[] = $tenantData;
        }

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * Outputs tenants as YAML.
     *
     * @param TenantInterface[] $tenants
     */
    private function outputYaml(OutputInterface $output, array $tenants, bool $detailed): int
    {
        $output->writeln('tenants:');

        foreach ($tenants as $tenant) {
            $output->writeln(sprintf('  - id: %s', $tenant->getId()));
            $output->writeln(sprintf('    slug: %s', $tenant->getSlug()));

            if ($detailed) {
                $name = method_exists($tenant, 'getName') ? $tenant->getName() : null;
                $output->writeln(sprintf('    name: %s', $name ?? 'null'));
                $output->writeln(sprintf('    mailer_dsn: %s', $tenant->getMailerDsn() ?? 'null'));
                $output->writeln(sprintf('    messenger_dsn: %s', $tenant->getMessengerDsn() ?? 'null'));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Masks sensitive data in DSN strings for safe output.
     */
    private function maskSensitiveData(string $dsn): string
    {
        if ('N/A' === $dsn) {
            return $dsn;
        }

        // Mask passwords in DSN strings
        return preg_replace('/(:\/\/[^:]+:)[^@]+(@)/', '$1***$2', $dsn) ?? $dsn;
    }
}
