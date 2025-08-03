<?php

namespace Zhortein\MultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[AsCommand(
    name: 'tenant:create',
    description: 'Crée un nouveau tenant (collectivité, client, etc.)'
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
            ->addArgument('slug', InputArgument::REQUIRED, 'Identifiant unique du tenant (ex: genlis)')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom complet du tenant (ex: Ville de Genlis)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $name = $input->getArgument('name');

        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository($this->tenantEntityClass);
        if ($repo->findOneBy(['slug' => $slug])) {
            $io->error("Un tenant avec le slug '{$slug}' existe déjà.");
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

        $io->success("Tenant '{$name}' créé avec succès (slug: {$slug}).");

        return Command::SUCCESS;
    }
}
