<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\DependencyInjection\TenantScope;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\TenantScope
 */
final class TenantScopeTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantScope $scope;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->scope = new TenantScope($this->tenantContext);
    }

    public function testGetWithTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext
            ->expects($this->exactly(2))
            ->method('getTenant')
            ->willReturn($tenant);

        $service = new \stdClass();
        $factory = fn () => $service;

        $result1 = $this->scope->get('test.service', $factory);
        $result2 = $this->scope->get('test.service', $factory);

        $this->assertSame($service, $result1);
        $this->assertSame($service, $result2); // Should return cached instance
    }

    public function testGetWithoutTenant(): void
    {
        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant is currently set in the context.');

        $this->scope->get('test.service', fn () => new \stdClass());
    }

    public function testGetWithDifferentTenants(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getId')->willReturn('tenant-1');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getId')->willReturn('tenant-2');

        $service1 = new \stdClass();
        $service2 = new \stdClass();

        // First call with tenant1
        $this->tenantContext
            ->expects($this->exactly(4))
            ->method('getTenant')
            ->willReturnCallback(function () use ($tenant1, $tenant2) {
                static $callCount = 0;
                $callCount++;
                return $callCount <= 2 ? $tenant1 : $tenant2;
            });

        $result1 = $this->scope->get('test.service', fn () => $service1);
        $result1Again = $this->scope->get('test.service', fn () => $service1);

        // Second call with tenant2 (should clear cache and create new service)
        $result2 = $this->scope->get('test.service', fn () => $service2);
        $result2Again = $this->scope->get('test.service', fn () => $service2);

        $this->assertSame($service1, $result1);
        $this->assertSame($service1, $result1Again);
        $this->assertSame($service2, $result2);
        $this->assertSame($service2, $result2Again);
        $this->assertNotSame($result1, $result2);
    }

    public function testClear(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext
            ->expects($this->exactly(3))
            ->method('getTenant')
            ->willReturn($tenant);

        $service1 = new \stdClass();
        $service2 = new \stdClass();

        // Create service
        $result1 = $this->scope->get('test.service', fn () => $service1);

        // Clear scope
        $this->scope->clear();

        // Get service again (should create new instance)
        $result2 = $this->scope->get('test.service', fn () => $service2);

        $this->assertSame($service1, $result1);
        $this->assertSame($service2, $result2);
        $this->assertNotSame($result1, $result2);
    }

    public function testClearAll(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext
            ->expects($this->exactly(2))
            ->method('getTenant')
            ->willReturn($tenant);

        $service1 = new \stdClass();
        $service2 = new \stdClass();

        // Create service
        $result1 = $this->scope->get('test.service', fn () => $service1);

        // Clear all
        $this->scope->clearAll();

        // Get service again (should create new instance)
        $result2 = $this->scope->get('test.service', fn () => $service2);

        $this->assertSame($service1, $result1);
        $this->assertSame($service2, $result2);
        $this->assertNotSame($result1, $result2);
    }

    public function testGetCurrentTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        // Trigger service creation to set current tenant
        $this->scope->get('test.service', fn () => new \stdClass());

        $this->assertSame($tenant, $this->scope->getCurrentTenant());
    }

    public function testGetCurrentTenantWhenNoneSet(): void
    {
        $this->assertNull($this->scope->getCurrentTenant());
    }

    public function testScopeName(): void
    {
        $this->assertSame('tenant', TenantScope::SCOPE_NAME);
    }
}