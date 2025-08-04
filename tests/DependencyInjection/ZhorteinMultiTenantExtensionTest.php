<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;

/**
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension
 */
class ZhorteinMultiTenantExtensionTest extends TestCase
{
    private ZhorteinMultiTenantExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new ZhorteinMultiTenantExtension();
        $this->container = new ContainerBuilder();
    }

    public function testLoadWithMailerEnabled(): void
    {
        // Arrange
        $config = [
            'zhortein_multi_tenant' => [
                'mailer' => [
                    'enabled' => true,
                    'fallback_dsn' => 'smtp://localhost:1025',
                    'fallback_from' => 'noreply@example.com',
                    'fallback_sender' => 'Test App',
                ],
            ],
        ];

        // Act
        $this->extension->load($config, $this->container);

        // Assert
        $this->assertTrue($this->container->hasParameter('zhortein_multi_tenant.mailer.enabled'));
        $this->assertTrue($this->container->getParameter('zhortein_multi_tenant.mailer.enabled'));
        $this->assertSame('smtp://localhost:1025', $this->container->getParameter('zhortein_multi_tenant.mailer.fallback_dsn'));
        $this->assertSame('noreply@example.com', $this->container->getParameter('zhortein_multi_tenant.mailer.fallback_from'));
        $this->assertSame('Test App', $this->container->getParameter('zhortein_multi_tenant.mailer.fallback_sender'));

        // Check that mailer services are registered
        $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.mailer.configurator'));
        $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.mailer.transport_factory'));
        $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.mailer.aware_mailer'));
    }

    public function testLoadWithMailerDisabled(): void
    {
        // Arrange
        $config = [
            'zhortein_multi_tenant' => [
                'mailer' => [
                    'enabled' => false,
                ],
            ],
        ];

        // Act
        $this->extension->load($config, $this->container);

        // Assert
        $this->assertTrue($this->container->hasParameter('zhortein_multi_tenant.mailer.enabled'));
        $this->assertFalse($this->container->getParameter('zhortein_multi_tenant.mailer.enabled'));

        // Check that mailer services are NOT registered
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.mailer.configurator'));
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.mailer.transport_factory'));
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.mailer.aware_mailer'));
    }

    public function testLoadWithMessengerEnabled(): void
    {
        // Arrange
        $config = [
            'zhortein_multi_tenant' => [
                'messenger' => [
                    'enabled' => true,
                    'default_transport' => 'async',
                    'add_tenant_headers' => true,
                    'tenant_transport_map' => [
                        'acme' => 'acme_transport',
                        'bio' => 'bio_transport',
                    ],
                    'fallback_dsn' => 'sync://',
                    'fallback_bus' => 'messenger.bus.default',
                ],
            ],
        ];

        // Act
        $this->extension->load($config, $this->container);

        // Assert
        $this->assertTrue($this->container->hasParameter('zhortein_multi_tenant.messenger.enabled'));
        $this->assertTrue($this->container->getParameter('zhortein_multi_tenant.messenger.enabled'));
        $this->assertSame('async', $this->container->getParameter('zhortein_multi_tenant.messenger.default_transport'));
        $this->assertTrue($this->container->getParameter('zhortein_multi_tenant.messenger.add_tenant_headers'));
        $this->assertSame([
            'acme' => 'acme_transport',
            'bio' => 'bio_transport',
        ], $this->container->getParameter('zhortein_multi_tenant.messenger.tenant_transport_map'));

        // Check that messenger services are registered (only if Messenger component is available)
        if (class_exists('Symfony\Component\Messenger\MessageBusInterface')) {
            $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.messenger.configurator'));
            $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.messenger.transport_factory'));
            $this->assertTrue($this->container->hasDefinition('zhortein_multi_tenant.messenger.transport_resolver'));
        }
    }

    public function testLoadWithMessengerDisabled(): void
    {
        // Arrange
        $config = [
            'zhortein_multi_tenant' => [
                'messenger' => [
                    'enabled' => false,
                ],
            ],
        ];

        // Act
        $this->extension->load($config, $this->container);

        // Assert
        $this->assertTrue($this->container->hasParameter('zhortein_multi_tenant.messenger.enabled'));
        $this->assertFalse($this->container->getParameter('zhortein_multi_tenant.messenger.enabled'));

        // Check that messenger services are NOT registered
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.messenger.configurator'));
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.messenger.transport_factory'));
        $this->assertFalse($this->container->hasDefinition('zhortein_multi_tenant.messenger.transport_resolver'));
    }

    public function testLoadWithDefaultConfiguration(): void
    {
        // Arrange
        $config = [
            'zhortein_multi_tenant' => [],
        ];

        // Act
        $this->extension->load($config, $this->container);

        // Assert - Check default values
        $this->assertFalse($this->container->getParameter('zhortein_multi_tenant.mailer.enabled'));
        $this->assertFalse($this->container->getParameter('zhortein_multi_tenant.messenger.enabled'));
    }

    public function testGetAlias(): void
    {
        // Act & Assert
        $this->assertSame('zhortein_multi_tenant', $this->extension->getAlias());
    }
}