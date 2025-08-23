<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ConditionalCacheDecoratorsPass;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;

/**
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ConditionalCacheDecoratorsPass
 */
final class ConditionalCacheDecoratorsPassTest extends TestCase
{
    private ConditionalCacheDecoratorsPass $compilerPass;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->compilerPass = new ConditionalCacheDecoratorsPass();
        $this->container = new ContainerBuilder();
    }

    public function testProcessSkipsWhenCacheDecoratorsDisabled(): void
    {
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', false);
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.services', ['cache.app']);

        $this->compilerPass->process($this->container);

        $this->assertFalse($this->container->hasDefinition('cache.app.simple.tenant_aware'));
    }

    public function testProcessSkipsWhenCacheDecoratorsParameterMissing(): void
    {
        $this->compilerPass->process($this->container);

        $this->assertFalse($this->container->hasDefinition('cache.app.simple.tenant_aware'));
    }

    public function testProcessSkipsWhenCacheServicesParameterMissing(): void
    {
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', true);

        $this->compilerPass->process($this->container);

        $this->assertFalse($this->container->hasDefinition('cache.app.simple.tenant_aware'));
    }

    public function testProcessSkipsWhenCacheServicesIsNotArray(): void
    {
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', true);
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.services', 'not-an-array');

        $this->compilerPass->process($this->container);

        $this->assertFalse($this->container->hasDefinition('cache.app.simple.tenant_aware'));
    }

    public function testProcessSkipsWhenSimpleServiceDoesNotExist(): void
    {
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', true);
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.services', ['cache.app']);

        $this->compilerPass->process($this->container);

        $this->assertFalse($this->container->hasDefinition('cache.app.simple.tenant_aware'));
    }

    public function testProcessSkipsNonStringServiceIds(): void
    {
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', true);
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.services', [123, 'cache.app']);
        
        // Add the simple service
        $this->container->register('cache.app.simple');

        $this->compilerPass->process($this->container);

        // Should not create decorator for numeric service ID
        $this->assertFalse($this->container->hasDefinition('123.simple.tenant_aware'));
        // Should create decorator for string service ID (if PSR-16 is available)
        if (interface_exists('Psr\SimpleCache\CacheInterface')) {
            $this->assertTrue($this->container->hasDefinition('cache.app.simple.tenant_aware'));
        }
    }

    public function testProcessRegistersDecoratorWhenConditionsAreMet(): void
    {
        // Skip test if PSR-16 interface is not available
        if (!interface_exists('Psr\SimpleCache\CacheInterface')) {
            $this->markTestSkipped('PSR-16 interface not available');
        }

        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', true);
        $this->container->setParameter('zhortein_multi_tenant.decorators.cache.services', ['cache.app']);
        
        // Add the simple service
        $this->container->register('cache.app.simple');

        $this->compilerPass->process($this->container);

        $this->assertTrue($this->container->hasDefinition('cache.app.simple.tenant_aware'));
        
        $definition = $this->container->getDefinition('cache.app.simple.tenant_aware');
        $this->assertSame(TenantAwareSimpleCacheDecorator::class, $definition->getClass());
        $this->assertTrue($definition->isAutowired());
        $this->assertSame('cache.app.simple', $definition->getDecoratedService()[0]);
    }
}