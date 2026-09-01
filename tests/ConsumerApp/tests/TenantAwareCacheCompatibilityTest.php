<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;

final class TenantAwareCacheCompatibilityTest extends KernelTestCase
{
    public function testRealSymfonyPoolRetainsItsContractsAndTenantIsolation(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $context = $container->get(TenantContextInterface::class);
        $cache = $container->get(CacheInterface::class);
        $namespacedCache = $container->get(NamespacedPoolInterface::class)->withSubNamespace('consumer_');

        self::assertInstanceOf(NamespacedPoolInterface::class, $cache);

        $this->assertMissingContextFailsClosed($cache);

        $context->setTenant(new Tenant('a', 'a'));
        self::assertSame('value-a', $cache->get('shared-key', static fn (): string => 'value-a'));
        self::assertTrue($namespacedCache->save($namespacedCache->getItem('shared-key')->set('namespaced-a')));

        $context->setTenant(new Tenant('b', 'b'));
        self::assertSame('value-b', $cache->get('shared-key', static fn (): string => 'value-b'));
        self::assertFalse($namespacedCache->getItem('shared-key')->isHit());

        $context->setTenant(new Tenant('a', 'a'));
        self::assertSame('value-a', $cache->get('shared-key', static fn (): string => 'leaked'));
        self::assertSame('namespaced-a', $namespacedCache->getItem('shared-key')->get());

        $context->clear();
        $this->assertMissingContextFailsClosed($cache);

        $global = $container->get('cache.global');
        self::assertSame('global-value', $global->get('shared-key', static fn (): string => 'global-value'));
    }

    private function assertMissingContextFailsClosed(CacheInterface $cache): void
    {
        try {
            $cache->get('shared-key', static fn (): string => 'must-not-be-written');
            self::fail('Tenant-aware cache operations without context must fail closed.');
        } catch (TenantCacheException) {
        }
    }
}
