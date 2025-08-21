<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to impersonate a tenant for administrative purposes.
 *
 * This command allows administrators to temporarily set tenant context
 * and execute operations as if they were that tenant.
 *
 * Security: This command should only be available to administrators.
 */
#[AsCommand(
    name: 'tenant:impersonate',
    description: 'Impersonate a tenant for administrative operations (admin-only)'
)]
final class TenantImpersonateCommand extends AbstractTenantAwareCommand
{
    public function __construct(
        TenantRegistryInterface $tenantRegistry,
        TenantContextInterface $tenantContext,
        private readonly bool $allowImpersonation = true,
    ) {
        parent::__construct($tenantRegistry, $tenantContext);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('tenant-identifier', InputArgument::REQUIRED, 'Tenant slug or ID to impersonate')
            ->addOption('command', 'c', InputOption::VALUE_REQUIRED, 'Command to execute in tenant context')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Start interactive mode with tenant context')
            ->addOption('show-config', null, InputOption::VALUE_NONE, 'Show tenant configuration after impersonation')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command allows administrators to impersonate a tenant:

    <info>%command.full_name% acme</info>

You can execute a specific command in the tenant context:

    <info>%command.full_name% acme --command="doctrine:schema:validate"</info>

You can start an interactive session with tenant context:

    <info>%command.full_name% acme --interactive</info>

You can also show the tenant configuration:

    <info>%command.full_name% acme --show-config</info>

<comment>Security Note:</comment> This command is intended for administrative use only.
It allows full access to tenant data and should be restricted appropriately.
EOT
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if impersonation is allowed
        if (!$this->allowImpersonation) {
            $io->error('Tenant impersonation is disabled in this environment.');

            return Command::FAILURE;
        }

        // Security warning
        $io->warning([
            'SECURITY WARNING: You are about to impersonate a tenant.',
            'This gives you full access to tenant data.',
            'Use this command responsibly and only for administrative purposes.',
        ]);

        $tenantIdentifier = $input->getArgument('tenant-identifier');
        if (!is_string($tenantIdentifier)) {
            $io->error('Invalid tenant identifier provided.');

            return Command::FAILURE;
        }

        // Resolve tenant
        $tenant = $this->resolveTenant($tenantIdentifier);
        if (null === $tenant) {
            $io->error(sprintf('Tenant not found: %s', $tenantIdentifier));

            return Command::FAILURE;
        }

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        $io->success(sprintf('Successfully impersonating tenant: %s (ID: %s)', $tenant->getSlug(), $tenant->getId()));

        // Show tenant configuration if requested
        if ($input->getOption('show-config')) {
            $this->showTenantConfiguration($io, $tenant);
        }

        // Execute specific command if provided
        $command = $input->getOption('command');
        if (is_string($command) && '' !== $command) {
            return $this->executeCommand($io, $command);
        }

        // Start interactive mode if requested
        if ($input->getOption('interactive')) {
            return $this->startInteractiveMode($io, $tenant);
        }

        // Default: just show impersonation status
        $io->note([
            'Tenant context has been set.',
            'You can now run other commands that will operate in this tenant context.',
            'Use --command option to execute a specific command or --interactive for interactive mode.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Shows tenant configuration in a safe manner.
     */
    private function showTenantConfiguration(SymfonyStyle $io, TenantInterface $tenant): void
    {
        $io->section('Tenant Configuration');

        $config = [
            ['Property', 'Value'],
            ['ID', (string) $tenant->getId()],
            ['Slug', $tenant->getSlug()],
        ];

        // Add name if available
        if (method_exists($tenant, 'getName')) {
            $name = $tenant->getName();
            $nameStr = is_string($name) ? $name : 'N/A';
            $config[] = ['Name', $nameStr];
        }

        // Add mailer DSN (masked for security)
        $mailerDsn = $tenant->getMailerDsn();
        $config[] = ['Mailer DSN', $mailerDsn ? $this->maskSensitiveData($mailerDsn) : 'N/A'];

        // Add messenger DSN (masked for security)
        $messengerDsn = $tenant->getMessengerDsn();
        $config[] = ['Messenger DSN', $messengerDsn ? $this->maskSensitiveData($messengerDsn) : 'N/A'];

        $io->table([], $config);
    }

    /**
     * Masks sensitive data in DSN strings.
     */
    private function maskSensitiveData(string $dsn): string
    {
        // Mask passwords in DSN strings
        return preg_replace('/(:\/\/[^:]+:)[^@]+(@)/', '$1***$2', $dsn) ?? $dsn;
    }

    /**
     * Executes a command in the tenant context.
     */
    private function executeCommand(SymfonyStyle $io, string $command): int
    {
        $io->section(sprintf('Executing command: %s', $command));

        // Note: In a real implementation, you would need to properly execute
        // the command with the current application context
        $io->note([
            'Command execution in tenant context would be implemented here.',
            'This requires integration with Symfony\'s Application class.',
            'For now, this is a placeholder showing the concept.',
        ]);

        $io->text(sprintf('Would execute: <info>%s</info>', $command));
        $io->text(sprintf('In tenant context: <comment>%s</comment>', $this->getCurrentTenant()?->getSlug() ?? 'none'));

        return Command::SUCCESS;
    }

    /**
     * Starts interactive mode with tenant context.
     */
    private function startInteractiveMode(SymfonyStyle $io, TenantInterface $tenant): int
    {
        $io->section('Interactive Tenant Mode');
        $io->note(sprintf('You are now in interactive mode for tenant: %s', $tenant->getSlug()));

        $io->text([
            'In a full implementation, this would:',
            '- Start an interactive shell with tenant context',
            '- Allow running multiple commands in sequence',
            '- Maintain tenant context across commands',
            '- Provide tenant-specific command completion',
        ]);

        $io->comment('Interactive mode is a placeholder in this implementation.');

        return Command::SUCCESS;
    }

    protected function requiresTenant(): bool
    {
        return false; // We handle tenant resolution manually in this command
    }
}
