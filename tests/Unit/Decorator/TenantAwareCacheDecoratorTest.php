<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheItem;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator
 */
final class TenantAwareCacheDecoratorTest extends TestCase
{
    private CacheItemPoolInterface $decoratedCache;
    private TenantContextInterface $tenantContext;
    private TenantInterface $tenant;

    protected function setUp(): void
    {
        $this->decoratedCache = $this->createMock(CacheItemPoolInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getId')->willReturn('tenant-123');
    }

    public function testGetItemWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $originalItem = $this->createMock(CacheItemInterface::class);
        $this->decoratedCache->expects($this->once())
            ->method('getItem')
            ->with('tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_test-key')
            ->willReturn($originalItem);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->getItem('test-key');

        $this->assertInstanceOf(TenantAwareCacheItem::class, $result);
        $this->assertSame('test-key', $result->getKey());
    }

    public function testGetItemWithoutTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);
        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);

        $this->expectException(TenantCacheException::class);
        $decorator->getItem('test-key');
    }

    public function testGetItemWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $originalItem = $this->createMock(CacheItemInterface::class);
        $this->decoratedCache->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($originalItem);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext, false);
        $result = $decorator->getItem('test-key');

        $this->assertInstanceOf(TenantAwareCacheItem::class, $result);
        $this->assertSame('test-key', $result->getKey());
    }

    public function testGetItemsWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $originalItem1 = $this->createMock(CacheItemInterface::class);
        $originalItem2 = $this->createMock(CacheItemInterface::class);

        $this->decoratedCache->expects($this->once())
            ->method('getItems')
            ->with(['tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key1', 'tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key2'])
            ->willReturn([
                'tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key1' => $originalItem1,
                'tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key2' => $originalItem2,
            ]);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->getItems(['key1', 'key2']);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('key1', $result);
        $this->assertArrayHasKey('key2', $result);
        $this->assertInstanceOf(TenantAwareCacheItem::class, $result['key1']);
        $this->assertInstanceOf(TenantAwareCacheItem::class, $result['key2']);
    }

    public function testHasItemWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('hasItem')
            ->with('tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_test-key')
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->hasItem('test-key');

        $this->assertTrue($result);
    }

    public function testDeleteItemWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('deleteItem')
            ->with('tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_test-key')
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->deleteItem('test-key');

        $this->assertTrue($result);
    }

    public function testDeleteItemsWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $this->decoratedCache->expects($this->once())
            ->method('deleteItems')
            ->with(['tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key1', 'tenant_099e0572953afd9db57624a7a1f82da24ef15bb7fc1aa16ce27b1c36312871ab_key2'])
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->deleteItems(['key1', 'key2']);

        $this->assertTrue($result);
    }

    public function testSaveWithTenantAwareCacheItem(): void
    {
        $originalItem = $this->createMock(CacheItemInterface::class);
        $tenantAwareItem = new TenantAwareCacheItem($originalItem, 'test-key');

        $this->decoratedCache->expects($this->once())
            ->method('save')
            ->with($originalItem)
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->save($tenantAwareItem);

        $this->assertTrue($result);
    }

    public function testSaveWithRegularCacheItemIsRejected(): void
    {
        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);

        $this->expectException(TenantCacheException::class);
        $decorator->save($this->createMock(CacheItemInterface::class));
    }

    public function testSaveDeferredWithTenantAwareCacheItem(): void
    {
        $originalItem = $this->createMock(CacheItemInterface::class);
        $tenantAwareItem = new TenantAwareCacheItem($originalItem, 'test-key');

        $this->decoratedCache->expects($this->once())
            ->method('saveDeferred')
            ->with($originalItem)
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->saveDeferred($tenantAwareItem);

        $this->assertTrue($result);
    }

    public function testCommit(): void
    {
        $this->decoratedCache->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);
        $result = $decorator->commit();

        $this->assertTrue($result);
    }

    public function testClearIsRejectedWhenThePoolCannotScopeIt(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);
        $decorator = new TenantAwareCacheDecorator($this->decoratedCache, $this->tenantContext);

        $this->expectException(TenantCacheException::class);
        $decorator->clear();
    }
}
