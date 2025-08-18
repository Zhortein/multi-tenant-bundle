<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheItem;

/**
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheItem
 */
final class TenantAwareCacheItemTest extends TestCase
{
    private CacheItemInterface $decoratedItem;

    protected function setUp(): void
    {
        $this->decoratedItem = $this->createMock(CacheItemInterface::class);
    }

    public function testGetKey(): void
    {
        $item = new TenantAwareCacheItem($this->decoratedItem, 'original-key');

        $this->assertSame('original-key', $item->getKey());
    }

    public function testGet(): void
    {
        $value = 'test-value';
        $this->decoratedItem->method('get')->willReturn($value);

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');

        $this->assertSame($value, $item->get());
    }

    public function testIsHit(): void
    {
        $this->decoratedItem->method('isHit')->willReturn(true);

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');

        $this->assertTrue($item->isHit());
    }

    public function testSet(): void
    {
        $value = 'new-value';
        $this->decoratedItem->expects($this->once())
            ->method('set')
            ->with($value)
            ->willReturnSelf();

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');
        $result = $item->set($value);

        $this->assertSame($item, $result);
    }

    public function testExpiresAt(): void
    {
        $expiration = new \DateTime('+1 hour');
        $this->decoratedItem->expects($this->once())
            ->method('expiresAt')
            ->with($expiration)
            ->willReturnSelf();

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');
        $result = $item->expiresAt($expiration);

        $this->assertSame($item, $result);
    }

    public function testExpiresAfterWithDateInterval(): void
    {
        $interval = new \DateInterval('PT1H');
        $this->decoratedItem->expects($this->once())
            ->method('expiresAfter')
            ->with($interval)
            ->willReturnSelf();

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');
        $result = $item->expiresAfter($interval);

        $this->assertSame($item, $result);
    }

    public function testExpiresAfterWithInteger(): void
    {
        $seconds = 3600;
        $this->decoratedItem->expects($this->once())
            ->method('expiresAfter')
            ->with($seconds)
            ->willReturnSelf();

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');
        $result = $item->expiresAfter($seconds);

        $this->assertSame($item, $result);
    }

    public function testExpiresAfterWithNull(): void
    {
        $this->decoratedItem->expects($this->once())
            ->method('expiresAfter')
            ->with(null)
            ->willReturnSelf();

        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');
        $result = $item->expiresAfter(null);

        $this->assertSame($item, $result);
    }

    public function testGetDecoratedItem(): void
    {
        $item = new TenantAwareCacheItem($this->decoratedItem, 'test-key');

        $this->assertSame($this->decoratedItem, $item->getDecoratedItem());
    }
}
