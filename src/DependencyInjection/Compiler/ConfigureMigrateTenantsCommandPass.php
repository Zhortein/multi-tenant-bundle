<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Command\MigrateTenantsCommand;

/** @internal Bridges the named alias targets exposed by supported DoctrineBundle versions. */
final class ConfigureMigrateTenantsCommandPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MigrateTenantsCommand::class)) {
            return;
        }

        $currentTarget = '.'.Connection::class.' $default';
        if ($container->hasAlias($currentTarget)) {
            return;
        }

        $legacyAlias = Connection::class.' $defaultConnection';
        $connectionAlias = $container->hasAlias($legacyAlias) ? $legacyAlias : Connection::class;

        if ($container->hasAlias($connectionAlias)) {
            $container->getDefinition(MigrateTenantsCommand::class)
                ->setArgument('$defaultConnection', new Reference($connectionAlias));
        }
    }
}
