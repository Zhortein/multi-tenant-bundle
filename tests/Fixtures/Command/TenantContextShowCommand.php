<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

#[AsCommand(name: 'tenant:context:show')]
final class TenantContextShowCommand extends Command
{
    /** @var list<string|null> */
    private array $observedTenants = [];

    public function __construct(private readonly TenantRegistryInterface $registry, private readonly TenantContextInterface $context)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selected = $input->getOption('tenant');
        $slug = is_string($selected) && '' !== $selected ? $selected : getenv('TENANT_ID');
        if (!is_string($slug) || '' === $slug) {
            $this->observedTenants[] = null === $this->context->getTenant() ? null : $this->context->getTenant()?->getSlug();
            $output->writeln('No tenant context.');

            return Command::SUCCESS;
        }
        $tenant = $this->registry->findBySlug($slug);
        if (null === $tenant) {
            $output->writeln('Unknown tenant.');

            return Command::FAILURE;
        }

        try {
            $this->context->setTenant($tenant);
            $this->observedTenants[] = $this->context->getTenant()?->getSlug();
            $output->writeln('Tenant: '.$tenant->getSlug());

            return Command::SUCCESS;
        } finally {
            $this->context->clear();
        }
    }

    /** @return list<string|null> */
    public function observedTenants(): array
    {
        return $this->observedTenants;
    }
}
