<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Functional\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\Configuration;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\QueryTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * Functional tests for chain resolver configuration and dependency injection.
 *
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\Configuration
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension
 */
final class ChainResolverConfigurationTest extends TestCase
{
    public function testDefaultChainConfiguration(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $config = $processor->processConfiguration($configuration, []);

        $this->assertSame('path', $config['resolver']);
        $this->assertSame(['subdomain', 'path', 'header', 'query'], $config['resolver_chain']['order']);
        $this->assertTrue($config['resolver_chain']['strict']);
        $this->assertSame(['X-Tenant-Id', 'X-Tenant-Slug'], $config['resolver_chain']['header_allow_list']);
        $this->assertSame('tenant', $config['query']['parameter']);
    }

    public function testCustomChainConfiguration(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $customConfig = [
            'zhortein_multi_tenant' => [
                'resolver' => 'chain',
                'resolver_chain' => [
                    'order' => ['header', 'query', 'path'],
                    'strict' => false,
                    'header_allow_list' => ['X-Custom-Tenant'],
                ],
                'query' => [
                    'parameter' => 'tenant_slug',
                ],
                'header' => [
                    'name' => 'X-Custom-Tenant',
                ],
            ],
        ];

        $config = $processor->processConfiguration($configuration, [$customConfig['zhortein_multi_tenant']]);

        $this->assertSame('chain', $config['resolver']);
        $this->assertSame(['header', 'query', 'path'], $config['resolver_chain']['order']);
        $this->assertFalse($config['resolver_chain']['strict']);
        $this->assertSame(['X-Custom-Tenant'], $config['resolver_chain']['header_allow_list']);
        $this->assertSame('tenant_slug', $config['query']['parameter']);
        $this->assertSame('X-Custom-Tenant', $config['header']['name']);
    }

    public function testChainResolverServiceRegistration(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();

        $config = [
            'zhortein_multi_tenant' => [
                'resolver' => 'chain',
                'resolver_chain' => [
                    'order' => ['subdomain', 'path', 'header', 'query'],
                    'strict' => true,
                    'header_allow_list' => ['X-Tenant-Id'],
                ],
            ],
        ];

        $extension->load([$config['zhortein_multi_tenant']], $container);

        // Check that ChainTenantResolver is registered
        $this->assertTrue($container->hasDefinition(ChainTenantResolver::class));

        // Check that it's aliased as the main resolver interface
        $this->assertTrue($container->hasAlias(TenantResolverInterface::class));
        $this->assertSame(ChainTenantResolver::class, (string) $container->getAlias(TenantResolverInterface::class));

        // Check that individual resolvers are registered
        $this->assertTrue($container->hasDefinition('zhortein_multi_tenant.resolver.subdomain'));
        $this->assertTrue($container->hasDefinition('zhortein_multi_tenant.resolver.path'));
        $this->assertTrue($container->hasDefinition('zhortein_multi_tenant.resolver.header'));
        $this->assertTrue($container->hasDefinition('zhortein_multi_tenant.resolver.query'));

        // Check ChainTenantResolver configuration
        $chainDefinition = $container->getDefinition(ChainTenantResolver::class);
        $arguments = $chainDefinition->getArguments();

        $this->assertArrayHasKey('$order', $arguments);
        $this->assertSame(['subdomain', 'path', 'header', 'query'], $arguments['$order']);

        $this->assertArrayHasKey('$strict', $arguments);
        $this->assertTrue($arguments['$strict']);

        $this->assertArrayHasKey('$headerAllowList', $arguments);
        $this->assertSame(['X-Tenant-Id'], $arguments['$headerAllowList']);
    }

    public function testIndividualResolverConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();

        $config = [
            'zhortein_multi_tenant' => [
                'resolver' => 'chain',
                'subdomain' => [
                    'base_domain' => 'custom.com',
                    'excluded_subdomains' => ['api', 'admin'],
                ],
                'header' => [
                    'name' => 'X-Custom-Header',
                ],
                'query' => [
                    'parameter' => 'tenant_id',
                ],
            ],
        ];

        $extension->load([$config['zhortein_multi_tenant']], $container);

        // Check subdomain resolver configuration
        $subdomainDefinition = $container->getDefinition('zhortein_multi_tenant.resolver.subdomain');
        $this->assertSame('custom.com', $subdomainDefinition->getArgument('$baseDomain'));
        $this->assertSame(['api', 'admin'], $subdomainDefinition->getArgument('$excludedSubdomains'));

        // Check header resolver configuration
        $headerDefinition = $container->getDefinition('zhortein_multi_tenant.resolver.header');
        $this->assertSame('X-Custom-Header', $headerDefinition->getArgument('$headerName'));

        // Check query resolver configuration
        $queryDefinition = $container->getDefinition('zhortein_multi_tenant.resolver.query');
        $this->assertSame('tenant_id', $queryDefinition->getArgument('$parameterName'));
    }

    public function testSingleResolverConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();

        $config = [
            'zhortein_multi_tenant' => [
                'resolver' => 'query',
                'query' => [
                    'parameter' => 'tenant_slug',
                ],
            ],
        ];

        $extension->load([$config['zhortein_multi_tenant']], $container);

        // Check that QueryTenantResolver is registered and aliased
        $this->assertTrue($container->hasDefinition(QueryTenantResolver::class));
        $this->assertTrue($container->hasAlias(TenantResolverInterface::class));
        $this->assertSame(QueryTenantResolver::class, (string) $container->getAlias(TenantResolverInterface::class));

        // Check that ChainTenantResolver is NOT registered
        $this->assertFalse($container->hasDefinition(ChainTenantResolver::class));

        // Check configuration
        $queryDefinition = $container->getDefinition(QueryTenantResolver::class);
        $this->assertSame('tenant_slug', $queryDefinition->getArgument('$parameterName'));
    }

    public function testParametersAreSet(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();

        $config = [
            'zhortein_multi_tenant' => [
                'resolver' => 'chain',
                'resolver_chain' => [
                    'order' => ['header', 'query'],
                    'strict' => false,
                    'header_allow_list' => ['X-Test'],
                ],
                'query' => [
                    'parameter' => 'test_param',
                ],
            ],
        ];

        $extension->load([$config['zhortein_multi_tenant']], $container);

        $this->assertSame(['header', 'query'], $container->getParameter('zhortein_multi_tenant.resolver_chain.order'));
        $this->assertFalse($container->getParameter('zhortein_multi_tenant.resolver_chain.strict'));
        $this->assertSame(['X-Test'], $container->getParameter('zhortein_multi_tenant.resolver_chain.header_allow_list'));
        $this->assertSame('test_param', $container->getParameter('zhortein_multi_tenant.query.parameter'));
    }

    public function testExceptionListenerIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();

        $config = [
            'zhortein_multi_tenant' => [
                'resolver' => 'chain',
            ],
        ];

        $extension->load([$config['zhortein_multi_tenant']], $container);

        $this->assertTrue($container->hasDefinition('Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener'));

        $listenerDefinition = $container->getDefinition('Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener');
        $this->assertTrue($listenerDefinition->isAutowired());
        $this->assertTrue($listenerDefinition->isAutoconfigured());
    }
}
