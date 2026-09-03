<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Command\MigrateTenantsCommand;

final class MigrateTenantsCommandWiringTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['MULTIPLE_DOCTRINE_CONNECTIONS']);
        unset($_SERVER['CUSTOM_DEFAULT_CONNECTION_NAME']);

        parent::tearDown();
    }

    public function testCommandReceivesTheDefaultDoctrineConnection(): void
    {
        self::bootKernel(['environment' => 'migrate_command_default_connection']);

        $container = static::getContainer();
        $registry = $container->get(ManagerRegistry::class);

        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame(
            $registry->getConnection(),
            $this->defaultConnectionOf($container->get(MigrateTenantsCommand::class)),
        );
    }

    public function testCommandReceivesTheNamedDefaultConnectionWithMultipleManagers(): void
    {
        $_SERVER['MULTIPLE_DOCTRINE_CONNECTIONS'] = '1';
        self::bootKernel(['environment' => 'migrate_command_multiple_connections']);

        $container = static::getContainer();
        $registry = $container->get(ManagerRegistry::class);

        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertArrayHasKey('default', $registry->getConnectionNames());
        self::assertArrayHasKey('reporting', $registry->getConnectionNames());
        self::assertArrayHasKey('default', $registry->getManagerNames());
        self::assertArrayHasKey('reporting', $registry->getManagerNames());

        $commandConnection = $this->defaultConnectionOf($container->get(MigrateTenantsCommand::class));

        self::assertSame($registry->getConnection('default'), $commandConnection);
        self::assertNotSame($registry->getConnection('reporting'), $commandConnection);
    }

    public function testCommandReceivesACustomNamedDefaultConnection(): void
    {
        $_SERVER['MULTIPLE_DOCTRINE_CONNECTIONS'] = '1';
        $_SERVER['CUSTOM_DEFAULT_CONNECTION_NAME'] = '1';
        self::bootKernel(['environment' => 'migrate_command_custom_default_connection']);

        $container = static::getContainer();
        $registry = $container->get(ManagerRegistry::class);

        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertArrayHasKey('primary', $registry->getConnectionNames());
        self::assertArrayHasKey('reporting', $registry->getConnectionNames());
        self::assertArrayHasKey('primary', $registry->getManagerNames());
        self::assertArrayHasKey('reporting', $registry->getManagerNames());

        $commandConnection = $this->defaultConnectionOf($container->get(MigrateTenantsCommand::class));

        self::assertSame($registry->getConnection('primary'), $commandConnection);
        self::assertNotSame($registry->getConnection('reporting'), $commandConnection);
    }

    private function defaultConnectionOf(MigrateTenantsCommand $command): Connection
    {
        $readDefaultConnection = \Closure::bind(
            static fn (MigrateTenantsCommand $service): Connection => $service->defaultConnection,
            null,
            MigrateTenantsCommand::class,
        );

        if (null === $readDefaultConnection) {
            self::fail('Unable to inspect the command connection.');
        }

        return $readDefaultConnection($command);
    }
}
