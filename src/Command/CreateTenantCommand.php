<?php

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[AsCommand(
    name: 'tenant:create',
    description: 'Create a new tenant'
)]
final class CreateTenantCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Unique tenant identifier (ex:acme)')
            ->addArgument('name', InputArgument::REQUIRED, 'Full name of the tenant (ex:Acme customer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $name = $input->getArgument('name');

        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository($this->tenantEntityClass);
        if ($repo->findOneBy(['slug' => $slug])) {
            $io->error(sprintf('Un tenant avec le slug `%s` existe déjà.', $slug));

            return Command::FAILURE;
        }

        /** @var TenantInterface $tenant */
        $tenant = new ($this->tenantEntityClass)();
        $tenant->setSlug($slug);

        if (method_exists($tenant, 'setName')) {
            $tenant->setName($name);
        }

        $this->em->persist($tenant);
        $this->em->flush();

        $io->success(sprintf("Tenant `%s` créé avec succès (slug: `%s`).", $name, $slug));

        return Command::SUCCESS;
    }
}
