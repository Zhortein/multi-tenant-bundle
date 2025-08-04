<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;

/**
 * @covers \Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry
 */
final class InMemoryTenantRegistryTest extends TestCase
{
    public function testGetAllReturnsEmptyArrayWhenNoTenants(): void
    {
        $registry = new InMemoryTenantRegistry();

        $result = $registry->getAll();

        $this->assertSame([], $result);
    }

    public function testGetAllReturnsAllTenants(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant2 = $this->createMock(TenantInterface::class);

        $registry = new InMemoryTenantRegistry([$tenant1, $tenant2]);

        $result = $registry->getAll();

        $this->assertSame([$tenant1, $tenant2], $result);
    }

    public function testGetBySlugReturnsTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');

        $registry = new InMemoryTenantRegistry([$tenant]);

        $result = $registry->getBySlug('test-tenant');

        $this->assertSame($tenant, $result);
    }

    public function testGetBySlugThrowsExceptionWhenNotFound(): void
    {
        $registry = new InMemoryTenantRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Tenant with slug 'non-existent' not found.");

        $registry->getBySlug('non-existent');
    }

    public function testHasSlugReturnsTrueWhenTenantExists(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');

        $registry = new InMemoryTenantRegistry([$tenant]);

        $result = $registry->hasSlug('test-tenant');

        $this->assertTrue($result);
    }

    public function testHasSlugReturnsFalseWhenTenantDoesNotExist(): void
    {
        $registry = new InMemoryTenantRegistry();

        $result = $registry->hasSlug('non-existent');

        $this->assertFalse($result);
    }

    public function testAddTenant(): void
    {
        $registry = new InMemoryTenantRegistry();
        $tenant = $this->createMock(TenantInterface::class);

        $registry->addTenant($tenant);

        $result = $registry->getAll();
        $this->assertSame([$tenant], $result);
    }

    public function testRemoveTenantReturnsTrueWhenTenantExists(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');

        $registry = new InMemoryTenantRegistry([$tenant]);

        $result = $registry->removeTenant('test-tenant');

        $this->assertTrue($result);
        $this->assertSame([], $registry->getAll());
    }

    public function testRemoveTenantReturnsFalseWhenTenantDoesNotExist(): void
    {
        $registry = new InMemoryTenantRegistry();

        $result = $registry->removeTenant('non-existent');

        $this->assertFalse($result);
    }

    public function testRemoveTenantReindexesArray(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getSlug')->willReturn('tenant-1');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getSlug')->willReturn('tenant-2');

        $tenant3 = $this->createMock(TenantInterface::class);
        $tenant3->method('getSlug')->willReturn('tenant-3');

        $registry = new InMemoryTenantRegistry([$tenant1, $tenant2, $tenant3]);

        $registry->removeTenant('tenant-2');

        $result = $registry->getAll();
        $this->assertSame([$tenant1, $tenant3], $result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }
}
