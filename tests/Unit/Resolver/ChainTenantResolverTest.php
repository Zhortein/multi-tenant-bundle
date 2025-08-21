<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver
 */
final class ChainTenantResolverTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testResolveTenantReturnsFirstMatch(): void
    {
        $tenant1 = $this->createMockTenant('tenant1', 1);
        $tenant2 = $this->createMockTenant('tenant2', 2);

        $resolver1 = $this->createMockResolver(null);
        $resolver2 = $this->createMockResolver($tenant1);
        $resolver3 = $this->createMockResolver($tenant2);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2, 'third' => $resolver3],
            ['first', 'second', 'third'],
            false, // non-strict mode
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant1, $result);
    }

    public function testResolveTenantInStrictModeWithSameTenant(): void
    {
        $tenant = $this->createMockTenant('tenant1', 1);

        $resolver1 = $this->createMockResolver($tenant);
        $resolver2 = $this->createMockResolver($tenant);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantInStrictModeThrowsOnAmbiguity(): void
    {
        $tenant1 = $this->createMockTenant('tenant1', 1);
        $tenant2 = $this->createMockTenant('tenant2', 2);

        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request();

        $this->expectException(AmbiguousTenantResolutionException::class);
        $this->expectExceptionMessage('Ambiguous tenant resolution: resolvers first, second returned different tenants: tenant1, tenant2');

        $chainResolver->resolveTenant($request);
    }

    public function testResolveTenantInStrictModeThrowsOnNoResults(): void
    {
        $resolver1 = $this->createMockResolver(null);
        $resolver2 = $this->createMockResolver(null);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request();

        $this->expectException(TenantResolutionException::class);
        $this->expectExceptionMessage('No tenant could be resolved by any resolver in the chain');

        $chainResolver->resolveTenant($request);
    }

    public function testResolveTenantSkipsNonExistentResolvers(): void
    {
        $tenant = $this->createMockTenant('tenant1', 1);
        $resolver = $this->createMockResolver($tenant);

        $chainResolver = new ChainTenantResolver(
            ['existing' => $resolver],
            ['non-existent', 'existing'],
            false,
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantAppliesHeaderAllowList(): void
    {
        $tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $headerResolver = new HeaderTenantResolver($tenantRegistry, 'X-Custom-Header');

        $chainResolver = new ChainTenantResolver(
            ['header' => $headerResolver],
            ['header'],
            false,
            ['X-Tenant-Id'], // Only allow X-Tenant-Id, not X-Custom-Header
            $this->logger
        );

        $request = new Request();
        $request->headers->set('X-Custom-Header', 'some-tenant');
        $result = $chainResolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveTenantAllowsHeaderInAllowList(): void
    {
        $tenant = $this->createMockTenant('tenant1', 1);
        $tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $tenantRegistry->method('getBySlug')->with('tenant1')->willReturn($tenant);

        $headerResolver = new HeaderTenantResolver($tenantRegistry, 'X-Tenant-Id');

        $chainResolver = new ChainTenantResolver(
            ['header' => $headerResolver],
            ['header'],
            false,
            ['X-Tenant-Id'], // Allow X-Tenant-Id
            $this->logger
        );

        $request = new Request();
        $request->headers->set('X-Tenant-Id', 'tenant1');
        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantHandlesResolverExceptions(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolveTenant')->willThrowException(new \RuntimeException('Resolver error'));

        $chainResolver = new ChainTenantResolver(
            ['failing' => $resolver],
            ['failing'],
            false, // non-strict mode
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveTenantThrowsOnResolverExceptionInStrictMode(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolveTenant')->willThrowException(new \RuntimeException('Resolver error'));

        $chainResolver = new ChainTenantResolver(
            ['failing' => $resolver],
            ['failing'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request();

        $this->expectException(TenantResolutionException::class);
        $this->expectExceptionMessage('Resolver "failing" failed: Resolver error');

        $chainResolver->resolveTenant($request);
    }

    public function testGetOrder(): void
    {
        $order = ['first', 'second', 'third'];
        $chainResolver = new ChainTenantResolver([], $order, false);

        $this->assertSame($order, $chainResolver->getOrder());
    }

    public function testGetResolvers(): void
    {
        $resolvers = ['first' => $this->createMockResolver(null)];
        $chainResolver = new ChainTenantResolver($resolvers, ['first'], false);

        $this->assertSame($resolvers, $chainResolver->getResolvers());
    }

    public function testIsStrict(): void
    {
        $strictResolver = new ChainTenantResolver([], [], true);
        $nonStrictResolver = new ChainTenantResolver([], [], false);

        $this->assertTrue($strictResolver->isStrict());
        $this->assertFalse($nonStrictResolver->isStrict());
    }

    public function testGetHeaderAllowList(): void
    {
        $allowList = ['X-Tenant-Id', 'X-Custom'];
        $chainResolver = new ChainTenantResolver([], [], false, $allowList);

        $this->assertSame($allowList, $chainResolver->getHeaderAllowList());
    }

    private function createMockTenant(string $slug, int|string $id): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }

    private function createMockResolver(?TenantInterface $returnValue): TenantResolverInterface
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolveTenant')->willReturn($returnValue);

        return $resolver;
    }
}
