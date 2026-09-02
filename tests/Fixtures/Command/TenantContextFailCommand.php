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

#[AsCommand(name: 'tenant:context:fail')]
final class TenantContextFailCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryInterface $registry,
        private readonly TenantContextInterface $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getOption('tenant');
        if (is_string($slug) && null !== $tenant = $this->registry->findBySlug($slug)) {
            $this->context->setTenant($tenant);
        }

        throw new \RuntimeException('controlled command failure');
    }
}
