<?php

namespace Zhortein\MultiTenantBundle\Command;

use Zhortein\MultiTenantBundle\Resolver\TenantConfigurationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

#[AsCommand(
    name: 'tenant:settings:clear-cache',
    description: 'Purge le cache des paramètres des tenants',
)]
final class ClearTenantSettingsCacheCommand extends Command
{
    public function __construct(
        private readonly TenantSettingsManager $settings
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settings->clearCache(); // Méthode à créer (cf. ci-dessous)
        $output->writeln('<info>Le cache des paramètres a été vidé avec succès.</info>');

        return Command::SUCCESS;
    }
}