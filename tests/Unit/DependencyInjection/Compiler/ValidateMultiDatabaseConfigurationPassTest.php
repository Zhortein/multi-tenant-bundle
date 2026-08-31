<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ValidateMultiDatabaseConfigurationPass;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionLifecycleInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;

final class ValidateMultiDatabaseConfigurationPassTest extends TestCase
{
    public function testSharedDatabaseDoesNotRequireMultiDatabaseProvider(): void
    {
        $container = $this->container('shared_db');

        (new ValidateMultiDatabaseConfigurationPass())->process($container);

        self::assertFalse($container->has(TenantConnectionParametersProviderInterface::class));
    }

    public function testMultiDatabaseWithoutLifecycleIsRejected(): void
    {
        $container = $this->container('multi_db');

        $this->expectExceptionMessage('requires exactly one TenantConnectionLifecycleInterface');
        (new ValidateMultiDatabaseConfigurationPass())->process($container);
    }

    public function testMultiDatabaseWithoutParametersProviderIsRejected(): void
    {
        $container = $this->container('multi_db');
        $container->register(TenantConnectionLifecycleInterface::class, \stdClass::class);

        $this->expectExceptionMessage('requires a TenantConnectionParametersProviderInterface alias');
        (new ValidateMultiDatabaseConfigurationPass())->process($container);
    }

    public function testCompleteMultiDatabaseConfigurationIsAccepted(): void
    {
        $container = $this->container('multi_db');
        $container->register(TenantConnectionLifecycleInterface::class, \stdClass::class);
        $container->register(TenantConnectionParametersProviderInterface::class, \stdClass::class);

        (new ValidateMultiDatabaseConfigurationPass())->process($container);

        self::assertTrue($container->hasDefinition(TenantConnectionParametersProviderInterface::class));
    }

    private function container(string $strategy): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('zhortein_multi_tenant.database.strategy', $strategy);

        return $container;
    }
}
