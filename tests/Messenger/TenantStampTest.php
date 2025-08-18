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
        $stamp = new TenantStamp('123');

        // Assert
        $this->assertSame('123', $stamp->getTenantId());
    }

    public function testConstructorWithStringId(): void
    {
        // Arrange & Act
        $stamp = new TenantStamp('tenant-uuid-123');

        // Assert
        $this->assertSame('tenant-uuid-123', $stamp->getTenantId());
    }
}
