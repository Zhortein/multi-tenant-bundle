<?php

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:list',
    description: 'Affiche tous les tenants'
)]
final class ListTenantsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenants = $this->em->getRepository($this->tenantEntityClass)->findAll();

        if (empty($tenants)) {
            $io->warning('Aucun tenant trouvé.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $rows[] = [
                method_exists($tenant, 'getId') ? $tenant->getId() : '-',
                method_exists($tenant, 'getSlug') ? $tenant->getSlug() : '-',
                method_exists($tenant, 'getName') ? $tenant->getName() : '',
            ];
        }

        $io->table(['ID', 'Slug', 'Nom'], $rows);

        return Command::SUCCESS;
    }
}
