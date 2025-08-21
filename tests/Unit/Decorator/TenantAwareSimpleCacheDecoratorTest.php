<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator
 */
final class TenantAwareSimpleCacheDecoratorTest extends TestCase
{
    private CacheInterface $decoratedCache;
    private TenantContextInterface $tenantContext;
    private TenantInterface $tenant;

    protected function setUp(): void
    {
        $this->decoratedCache = $this->createMock(CacheInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getId')->willReturn('tenant-456');
        $this->tenant->method('getSlug')->willReturn('test-tenant');
    }

    public function testGetWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('get')
            ->with('tenant_tenant-456_test-key', 'default-value')
            ->willReturn('cached-value');

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->get('test-key', 'default-value');

        $this->assertSame('cached-value', $result);
    }

    public function testGetWithoutTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $this->decoratedCache->expects($this->once())
            ->method('get')
            ->with('test-key', 'default-value')
            ->willReturn('cached-value');

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->get('test-key', 'default-value');

        $this->assertSame('cached-value', $result);
    }

    public function testGetWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('get')
            ->with('test-key', 'default-value')
            ->willReturn('cached-value');

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext, false);
        $result = $decorator->get('test-key', 'default-value');

        $this->assertSame('cached-value', $result);
    }

    public function testSetWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('set')
            ->with('tenant_tenant-456_test-key', 'test-value', 3600)
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->set('test-key', 'test-value', 3600);

        $this->assertTrue($result);
    }

    public function testDeleteWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('delete')
            ->with('tenant_tenant-456_test-key')
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->delete('test-key');

        $this->assertTrue($result);
    }

    public function testClear(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->clear();

        $this->assertTrue($result);
    }

    public function testGetMultipleWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('getMultiple')
            ->with(['tenant_tenant-456_key1', 'tenant_tenant-456_key2'], 'default')
            ->willReturn([
                'tenant_tenant-456_key1' => 'value1',
                'tenant_tenant-456_key2' => 'value2',
            ]);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->getMultiple(['key1', 'key2'], 'default');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    public function testSetMultipleWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('setMultiple')
            ->with([
                'tenant_tenant-456_key1' => 'value1',
                'tenant_tenant-456_key2' => 'value2',
            ], 3600)
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 3600);

        $this->assertTrue($result);
    }

    public function testDeleteMultipleWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('deleteMultiple')
            ->with(['tenant_tenant-456_key1', 'tenant_tenant-456_key2'])
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->deleteMultiple(['key1', 'key2']);

        $this->assertTrue($result);
    }

    public function testHasWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('has')
            ->with('tenant_tenant-456_test-key')
            ->willReturn(true);

        $decorator = new TenantAwareSimpleCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->has('test-key');

        $this->assertTrue($result);
    }
}
