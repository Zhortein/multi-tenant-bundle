<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManagerInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator
 */
class TenantMessengerConfiguratorTest extends TestCase
{
    private TenantSettingsManagerInterface $settingsManager;
    private TenantMessengerConfigurator $configurator;

    protected function setUp(): void
    {
        $this->settingsManager = $this->createMock(TenantSettingsManagerInterface::class);

        $this->configurator = new TenantMessengerConfigurator(
            $this->settingsManager,
            'sync://',
            'messenger.bus.default'
        );
    }

    public function testGetTransportDsnWithTenantSetting(): void
    {
        // Arrange
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
        $this->settingsManager->method('get')->with('messenger_transport_dsn', 'sync://')->willReturn('sync://');

        // Act
        $result = $this->configurator->getTransportDsn();

        // Assert
        $this->assertSame('sync://', $result);
    }

    public function testGetBusNameWithTenantSetting(): void
    {
        // Arrange
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
        $this->settingsManager->method('get')
            ->with('messenger_delay', 0)
            ->willReturn(10000);

        // Act
        $result = $this->configurator->getDelay();

        // Assert
        $this->assertSame(10000, $result);
    }

    public function testGetDelayWithSpecificTransport(): void
    {
        // Arrange
        $this->settingsManager->expects($this->once())
            ->method('get')
            ->with('messenger_delay_email', 0)
            ->willReturn(15000);

        // Act
        $result = $this->configurator->getDelay('email');

        // Assert
        $this->assertSame(15000, $result);
    }

    public function testGetDelayWithSpecificTransportFallbackToDefault(): void
    {
        // Arrange
        $this->settingsManager->expects($this->once())
            ->method('get')
            ->with('messenger_delay_email', 0)
            ->willReturn(null);

        // Act
        $result = $this->configurator->getDelay('email');

        // Assert
        $this->assertSame(0, $result);
    }

    public function testGetDelayWithoutTenant(): void
    {
        // Arrange
        $this->settingsManager->method('get')->with('messenger_delay', 0)->willReturn(0);

        // Act
        $result = $this->configurator->getDelay();

        // Assert
        $this->assertSame(0, $result);
    }

    public function testGetDelayWithCustomDefault(): void
    {
        // Arrange
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
        $this->settingsManager->method('get')->willReturnCallback(static fn (string $key, mixed $default): mixed => $default);

        // Act & Assert
        $this->assertSame('sync://', $this->configurator->getTransportDsn());
        $this->assertSame('messenger.bus.default', $this->configurator->getBusName());
        $this->assertSame(0, $this->configurator->getDelay());
    }
}
