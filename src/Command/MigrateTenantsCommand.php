<?php

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

#[AsCommand(name: 'tenant:migrate', description: 'Execute Doctrine migrations for all tenants')]
class MigrateTenantsCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryInterface $tenantRegistry,
        private readonly TenantContext $tenantContext,
        private readonly TenantConnectionResolverInterface $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_OPTIONAL, 'Run migrations for a specific tenant slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('only');
        $tenants = $only
            ? [$this->tenantRegistry->getBySlug($only)]
            : $this->tenantRegistry->getAll();

        foreach ($tenants as $tenant) {
            $this->tenantContext->setTenant($tenant);

            $params = $this->resolver->resolveParameters($tenant);
            $output->writeln("\n🔧 <info>Running migrations for tenant:</info> <comment>{$tenant->getSlug()}</comment>");

            // Clone DependencyFactory avec les bons paramètres
            $dependencyFactory = $this->createTenantDependencyFactory($params);

            $migrator = $dependencyFactory->getMigrator();
            $migrator->migrate();
        }

        return Command::SUCCESS;
    }

    private function createTenantDependencyFactory(array $connectionParams): DependencyFactory
    {
        // ⚠️ À adapter : ici on recrée une mini-factory Doctrine, ou tu peux utiliser un service
        $configuration = new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray([
            'connection' => $connectionParams,
            'migrations_paths' => [
                'App\Migrations' => 'migrations',
            ],
        ]);

        $config = new \Doctrine\Migrations\Configuration\Migration\Configuration($connectionParams);
        $config->setMigrationsDirectory('migrations');
        $config->setMigrationsNamespace('App\Migrations');
        $config->setAllOrNothing(true);

        return DependencyFactory::fromConnection(new \Doctrine\Migrations\Configuration\Configuration($connectionParams), DriverManager::getConnection($connectionParams));
    }
}
