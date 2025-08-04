<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantStamp
 */
class TenantStampTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        // Arrange & Act
        $stamp = new TenantStamp('acme', 'Acme Corporation');

        // Assert
        $this->assertSame('acme', $stamp->getTenantSlug());
        $this->assertSame('Acme Corporation', $stamp->getTenantName());
    }

    public function testConstructorWithNullName(): void
    {
        // Arrange & Act
        $stamp = new TenantStamp('acme', null);

        // Assert
        $this->assertSame('acme', $stamp->getTenantSlug());
        $this->assertNull($stamp->getTenantName());
    }

    public function testToString(): void
    {
        // Arrange
        $stamp = new TenantStamp('acme', 'Acme Corporation');

        // Act
        $result = (string) $stamp;

        // Assert
        $this->assertSame('TenantStamp(acme, Acme Corporation)', $result);
    }

    public function testToStringWithNullName(): void
    {
        // Arrange
        $stamp = new TenantStamp('acme', null);

        // Act
        $result = (string) $stamp;

        // Assert
        $this->assertSame('TenantStamp(acme, )', $result);
    }
}
