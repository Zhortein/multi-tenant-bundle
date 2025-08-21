<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Base class for tenant-aware commands.
 *
 * Provides common functionality for commands that need to work with tenant context:
 * - Global --tenant option
 * - Environment variable TENANT_ID support
 * - Tenant resolution and context setting
 * - Safe error handling for unknown tenants
 */
abstract class AbstractTenantAwareCommand extends Command
{
    public function __construct(
        protected readonly TenantRegistryInterface $tenantRegistry,
        protected readonly TenantContextInterface $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            't',
            InputOption::VALUE_REQUIRED,
            'Tenant slug or ID to operate on (can also be set via TENANT_ID environment variable)'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        // Resolve tenant from --tenant option or TENANT_ID environment variable
        $tenantIdentifier = $this->resolveTenantIdentifier($input);

        if (null !== $tenantIdentifier) {
            $tenant = $this->resolveTenant($tenantIdentifier);
            if (null === $tenant) {
                $io = new SymfonyStyle($input, $output);
                $io->error(sprintf('Unknown tenant: %s', $tenantIdentifier));
                throw new \InvalidArgumentException(sprintf('Unknown tenant: %s', $tenantIdentifier));
            }

            $this->tenantContext->setTenant($tenant);
        }
    }

    /**
     * Resolves tenant identifier from input option or environment variable.
     */
    public function resolveTenantIdentifier(InputInterface $input): ?string
    {
        // Priority: --tenant option > TENANT_ID environment variable
        $tenantFromOption = $input->getOption('tenant');
        if (is_string($tenantFromOption) && '' !== $tenantFromOption) {
            return $tenantFromOption;
        }

        $tenantFromEnv = $_ENV['TENANT_ID'] ?? $_SERVER['TENANT_ID'] ?? null;
        if (is_string($tenantFromEnv) && '' !== $tenantFromEnv) {
            return $tenantFromEnv;
        }

        return null;
    }

    /**
     * Resolves tenant by slug or ID.
     */
    public function resolveTenant(string $identifier): ?TenantInterface
    {
        // Try by slug first
        $tenant = $this->tenantRegistry->findBySlug($identifier);
        if (null !== $tenant) {
            return $tenant;
        }

        // Try by ID if slug lookup failed
        if (ctype_digit($identifier)) {
            return $this->tenantRegistry->findById((int) $identifier);
        }

        // Try by ID as string
        return $this->tenantRegistry->findById($identifier);
    }

    /**
     * Gets the current tenant from context.
     */
    public function getCurrentTenant(): ?TenantInterface
    {
        return $this->tenantContext->getTenant();
    }

    /**
     * Checks if a tenant is required for this command.
     * Override in subclasses to enforce tenant requirement.
     */
    protected function requiresTenant(): bool
    {
        return false;
    }

    /**
     * Validates tenant requirement before command execution.
     */
    public function validateTenantRequirement(SymfonyStyle $io): bool
    {
        if ($this->requiresTenant() && null === $this->getCurrentTenant()) {
            $io->error('This command requires a tenant context. Use --tenant option or set TENANT_ID environment variable.');

            return false;
        }

        return true;
    }

    /**
     * Gets all tenants or a specific tenant based on context.
     *
     * @return TenantInterface[]
     */
    public function getTargetTenants(): array
    {
        $currentTenant = $this->getCurrentTenant();

        if (null !== $currentTenant) {
            return [$currentTenant];
        }

        return $this->tenantRegistry->getAll();
    }

    /**
     * Safely displays tenant information without exposing sensitive data.
     */
    protected function displayTenantInfo(SymfonyStyle $io, TenantInterface $tenant): void
    {
        $io->comment(sprintf('Operating on tenant: %s (ID: %s)', $tenant->getSlug(), $tenant->getId()));
    }

    /**
     * Execute the command.
     *
     * This method wraps the actual execution to ensure tenant context is cleared afterwards.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } finally {
            // Always clear tenant context after command execution
            $this->tenantContext->clear();
        }
    }

    /**
     * The actual command execution logic.
     * Subclasses should override this method instead of execute().
     */
    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;
}
