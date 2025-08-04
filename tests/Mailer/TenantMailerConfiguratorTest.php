<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManagerInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator
 */
class TenantMailerConfiguratorTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantSettingsManagerInterface $settingsManager;
    private TenantMailerConfigurator $configurator;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->settingsManager = $this->createMock(TenantSettingsManagerInterface::class);

        $this->configurator = new TenantMailerConfigurator(
            $this->tenantContext,
            $this->settingsManager,
            'smtp://localhost:1025',
            'noreply@example.com',
            'Default App'
        );
    }

    public function testGetMailerDsnWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('mailer_dsn', 'smtp://localhost:1025')
            ->willReturn('smtp://tenant.smtp.com:587');

        // Act
        $result = $this->configurator->getMailerDsn();

        // Assert
        $this->assertSame('smtp://tenant.smtp.com:587', $result);
    }

    public function testGetMailerDsnWithFallback(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        // Act
        $result = $this->configurator->getMailerDsn();

        // Assert
        $this->assertSame('smtp://localhost:1025', $result);
    }

    public function testGetFromAddressWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('email_from', 'noreply@example.com')
            ->willReturn('noreply@tenant.com');

        // Act
        $result = $this->configurator->getFromAddress();

        // Assert
        $this->assertSame('noreply@tenant.com', $result);
    }

    public function testGetSenderNameWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('email_sender', 'Default App')
            ->willReturn('Acme Corporation');

        // Act
        $result = $this->configurator->getSenderName();

        // Assert
        $this->assertSame('Acme Corporation', $result);
    }

    public function testGetReplyToAddressWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('email_reply_to', null)
            ->willReturn('support@tenant.com');

        // Act
        $result = $this->configurator->getReplyToAddress();

        // Assert
        $this->assertSame('support@tenant.com', $result);
    }

    public function testGetBccAddressWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('email_bcc', null)
            ->willReturn('admin@tenant.com');

        // Act
        $result = $this->configurator->getBccAddress();

        // Assert
        $this->assertSame('admin@tenant.com', $result);
    }

    public function testGetLogoUrlWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('logo_url', null)
            ->willReturn('https://tenant.com/logo.png');

        // Act
        $result = $this->configurator->getLogoUrl();

        // Assert
        $this->assertSame('https://tenant.com/logo.png', $result);
    }

    public function testGetPrimaryColorWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('primary_color', null)
            ->willReturn('#ff6b35');

        // Act
        $result = $this->configurator->getPrimaryColor();

        // Assert
        $this->assertSame('#ff6b35', $result);
    }

    public function testGetWebsiteUrlWithTenantSetting(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->settingsManager->method('get')
            ->with('website_url', null)
            ->willReturn('https://tenant.com');

        // Act
        $result = $this->configurator->getWebsiteUrl();

        // Assert
        $this->assertSame('https://tenant.com', $result);
    }

    public function testGetAllSettingsWithoutTenant(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        // Act & Assert
        $this->assertSame('smtp://localhost:1025', $this->configurator->getMailerDsn());
        $this->assertSame('noreply@example.com', $this->configurator->getFromAddress());
        $this->assertSame('Default App', $this->configurator->getSenderName());
        $this->assertNull($this->configurator->getReplyToAddress());
        $this->assertNull($this->configurator->getBccAddress());
        $this->assertNull($this->configurator->getLogoUrl());
        $this->assertNull($this->configurator->getPrimaryColor());
        $this->assertNull($this->configurator->getWebsiteUrl());
    }
}