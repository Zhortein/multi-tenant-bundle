<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Context\TenantContext
 */
final class TenantContextTest extends TestCase
{
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
    }

    public function testInitialStateHasNoTenant(): void
    {
        $this->assertNull($this->tenantContext->getTenant());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSetTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);

        $this->assertSame($tenant, $this->tenantContext->getTenant());
        $this->assertTrue($this->tenantContext->hasTenant());
    }

    public function testClearTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);
        $this->assertTrue($this->tenantContext->hasTenant());

        $this->tenantContext->clear();

        $this->assertNull($this->tenantContext->getTenant());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSetTenantOverwritesPrevious(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant2 = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant1);
        $this->assertSame($tenant1, $this->tenantContext->getTenant());

        $this->tenantContext->setTenant($tenant2);
        $this->assertSame($tenant2, $this->tenantContext->getTenant());
        $this->assertNotSame($tenant1, $this->tenantContext->getTenant());
    }
}
