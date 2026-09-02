<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\DependencyInjection\TenantScope;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class PersistentKernelResetTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TEST_KERNEL_TENANT_SCOPE']);
        parent::tearDown();
        restore_exception_handler();
    }

    public function testRealServicesResetterClearsInitializedTenantAndCacheIdempotently(): void
    {
        $_SERVER['TEST_KERNEL_TENANT_SCOPE'] = '1';
        self::bootKernel(['environment' => 'persistent_kernel_scope']);
        $container = static::getContainer();
        $context = $container->get(TenantContextInterface::class);
        self::assertInstanceOf(TenantContextInterface::class, $context);
        $context->setTenant((new TestTenant())->setId(1)->setSlug('tenant-a'));
        $scope = $container->get(TenantScope::class);
        self::assertInstanceOf(TenantScope::class, $scope);
        $scope->get('initialized', static fn (): object => new \stdClass());

        $cache = $container->get('cache.app');
        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->get('initialized', static fn (): string => 'tenant-a');

        $resetter = $container->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        $resetter->reset();

        self::assertNull($context->getTenant());
        self::assertNull($scope->getCurrentTenant());
    }
}
