<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Command\MigrateTenantsCommand;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ConfigureMigrateTenantsCommandPass;

final class ConfigureMigrateTenantsCommandPassTest extends TestCase
{
    public function testCurrentDoctrineTargetIsLeftForTheTargetAttribute(): void
    {
        $container = $this->containerWithCommand();
        $container->setAlias('.'.Connection::class.' $default', 'doctrine.dbal.default_connection');

        (new ConfigureMigrateTenantsCommandPass())->process($container);

        self::assertArrayNotHasKey(
            '$defaultConnection',
            $container->getDefinition(MigrateTenantsCommand::class)->getArguments(),
        );
    }

    public function testLegacyDoctrineNamedAliasIsWiredExplicitly(): void
    {
        $container = $this->containerWithCommand();
        $legacyAlias = Connection::class.' $defaultConnection';
        $container->setAlias($legacyAlias, 'doctrine.dbal.default_connection');

        (new ConfigureMigrateTenantsCommandPass())->process($container);

        $argument = $container->getDefinition(MigrateTenantsCommand::class)->getArgument('$defaultConnection');
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame($legacyAlias, (string) $argument);
    }

    public function testConfiguredDefaultConnectionIsUsedWhenItHasAnotherName(): void
    {
        $container = $this->containerWithCommand();
        $container->setAlias(Connection::class, 'doctrine.dbal.primary_connection');

        (new ConfigureMigrateTenantsCommandPass())->process($container);

        $argument = $container->getDefinition(MigrateTenantsCommand::class)->getArgument('$defaultConnection');
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame(Connection::class, (string) $argument);
    }

    private function containerWithCommand(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(MigrateTenantsCommand::class);

        return $container;
    }
}
