<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\TenantSetting;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

/**
 * @covers \Zhortein\MultiTenantBundle\Manager\TenantSettingsManager
 */
final class TenantSettingsManagerTest extends TestCase
{
    private TenantSettingsManager $manager;
    private TenantContextInterface $tenantContext;
    private TenantSettingRepository $repository;
    private CacheItemPoolInterface $cache;
    private ParameterBagInterface $parameterBag;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->repository = $this->createMock(TenantSettingRepository::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->parameterBag = $this->createMock(ParameterBagInterface::class);

        $this->manager = new TenantSettingsManager(
            $this->tenantContext,
            $this->repository,
            $this->cache,
            $this->parameterBag
        );
    }

    public function testGetWithCachedSettings(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['test_key' => 'test_value']);

        $this->cache->method('getItem')->with('zhortein_tenant_settings_tenant-1')->willReturn($cacheItem);

        $result = $this->manager->get('test_key', 'default');

        $this->assertEquals('test_value', $result);
    }

    public function testGetWithDefaultValue(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([]);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->parameterBag->method('get')->with('zhortein_multi_tenant.default_settings.missing_key')->willReturn(null);

        $result = $this->manager->get('missing_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function testGetRequiredThrowsExceptionWhenNotFound(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([]);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->parameterBag->method('get')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Tenant setting 'required_key' is required but not set.");

        $this->manager->getRequired('required_key');
    }

    public function testAllWithoutTenantThrowsException(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant set in context');

        $this->manager->all();
    }

    public function testAllLoadsFromRepositoryWhenNotCached(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $setting1 = $this->createMock(TenantSetting::class);
        $setting1->method('getKey')->willReturn('key1');
        $setting1->method('getValue')->willReturn('value1');

        $setting2 = $this->createMock(TenantSetting::class);
        $setting2->method('getKey')->willReturn('key2');
        $setting2->method('getValue')->willReturn('value2');

        $this->repository->method('findAllForTenant')->with($tenant)->willReturn([$setting1, $setting2]);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with(['key1' => 'value1', 'key2' => 'value2']);
        $cacheItem->method('get')->willReturn(['key1' => 'value1', 'key2' => 'value2']);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->expects($this->once())->method('save')->with($cacheItem);

        $result = $this->manager->all();

        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    public function testClearCacheWithoutTenantThrowsException(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant set in context');

        $this->manager->clearCache();
    }

    public function testClearCache(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $this->cache->expects($this->once())
            ->method('deleteItem')
            ->with('zhortein_tenant_settings_tenant-1');

        $this->manager->clearCache();
    }
}
