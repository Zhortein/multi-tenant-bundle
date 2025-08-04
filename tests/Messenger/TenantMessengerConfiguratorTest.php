<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManagerInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator
 */
class TenantMessengerConfiguratorTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantSettingsManagerInterface $settingsManager;
    private TenantMessengerConfigurator $configurator;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->settingsManager = $this->createMock(TenantSettingsManagerInterface::class);

        $this->configurator = new TenantMessengerConfigurator(
            $this->tenantContext,
            $this->settingsManager,
            'sync://',
            'messenger.bus.default'
        );
    }

    public function testGetTransportDsnWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('messenger_transport_dsn', 'sync://')
            ->willReturn('redis://localhost:6379/tenant_messages');

        // Act
        $result = $this->configurator->getTransportDsn();

        // Assert
        $this->assertSame('redis://localhost:6379/tenant_messages', $result);
    }

    public function testGetTransportDsnWithFallback(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        // Act
        $result = $this->configurator->getTransportDsn();

        // Assert
        $this->assertSame('sync://', $result);
    }

    public function testGetBusNameWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('messenger_bus', 'messenger.bus.default')
            ->willReturn('command.bus');

        // Act
        $result = $this->configurator->getBusName();

        // Assert
        $this->assertSame('command.bus', $result);
    }

    public function testGetDelayWithDefaultTransport(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('messenger_delay', 5000)
            ->willReturn(10000);

        // Act
        $result = $this->configurator->getDelay();

        // Assert
        $this->assertSame(10000, $result);
    }

    public function testGetDelayWithSpecificTransport(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);

        // First call for specific transport setting
        $this->settingsManager->expects($this->at(0))
            ->method('get')
            ->with('messenger_delay_email', null)
            ->willReturn(15000);

        // Act
        $result = $this->configurator->getDelay('email');

        // Assert
        $this->assertSame(15000, $result);
    }

    public function testGetDelayWithSpecificTransportFallbackToDefault(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);

        // First call for specific transport setting (returns null)
        $this->settingsManager->expects($this->at(0))
            ->method('get')
            ->with('messenger_delay_email', null)
            ->willReturn(null);

        // Second call for default delay setting
        $this->settingsManager->expects($this->at(1))
            ->method('get')
            ->with('messenger_delay', 5000)
            ->willReturn(8000);

        // Act
        $result = $this->configurator->getDelay('email');

        // Assert
        $this->assertSame(8000, $result);
    }

    public function testGetDelayWithoutTenant(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        // Act
        $result = $this->configurator->getDelay();

        // Assert
        $this->assertSame(5000, $result); // Default fallback
    }

    public function testGetDelayWithCustomDefault(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('messenger_delay', 12000)
            ->willReturn(null); // No tenant setting

        // Act
        $result = $this->configurator->getDelay(null, 12000);

        // Assert
        $this->assertSame(12000, $result);
    }

    public function testGetAllSettingsWithoutTenant(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        // Act & Assert
        $this->assertSame('sync://', $this->configurator->getTransportDsn());
        $this->assertSame('messenger.bus.default', $this->configurator->getBusName());
        $this->assertSame(5000, $this->configurator->getDelay());
    }
}
