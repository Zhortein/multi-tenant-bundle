<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Command to list all tenants in the system.
 */
#[AsCommand(
    name: 'tenant:list',
    description: 'Lists all tenants in the system'
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

        $repository = $this->em->getRepository($this->tenantEntityClass);

        /** @var TenantInterface[] $tenants */
        $tenants = $repository->findAll();

        if (empty($tenants)) {
            $io->warning('No tenants found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $rows[] = [
                $tenant->getId(),
                $tenant->getSlug(),
                method_exists($tenant, 'getName') ? $tenant->getName() : 'N/A',
                $tenant->getMailerDsn() ?? 'N/A',
                $tenant->getMessengerDsn() ?? 'N/A',
            ];
        }

        $io->table(['ID', 'Slug', 'Name', 'Mailer DSN', 'Messenger DSN'], $rows);
        $io->success(sprintf('Found %d tenant(s).', count($tenants)));

        return Command::SUCCESS;
    }
}
