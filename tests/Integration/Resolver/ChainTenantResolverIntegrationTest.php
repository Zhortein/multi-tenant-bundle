<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\QueryTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * Integration tests for ChainTenantResolver with real resolver implementations.
 *
 * @covers \Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver
 */
final class ChainTenantResolverIntegrationTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testPrecedenceOrderFirstResolverWins(): void
    {
        $tenant1 = $this->createMockTenant('tenant1', 1);
        $tenant2 = $this->createMockTenant('tenant2', 2);

        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            false,
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        // Should return first resolver result
        $this->assertSame($tenant1, $result);
    }

    public function testPrecedenceOrderWithNullFirst(): void
    {
        $tenant = $this->createMockTenant('tenant', 1);

        $resolver1 = $this->createMockResolver(null);
        $resolver2 = $this->createMockResolver($tenant);

        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            false,
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        // Should return second resolver result since first returned null
        $this->assertSame($tenant, $result);
    }

    public function testHeaderAllowListFiltering(): void
    {
        $allowedTenant = $this->createMockTenant('allowed-tenant', 1);
        $blockedTenant = $this->createMockTenant('blocked-tenant', 2);

        $this->tenantRegistry
            ->method('getBySlug')
            ->willReturnMap([
                ['allowed-tenant', $allowedTenant],
                ['blocked-tenant', $blockedTenant],
            ]);

        $resolvers = [
            'allowed_header' => new HeaderTenantResolver($this->tenantRegistry, 'X-Tenant-Id'),
            'blocked_header' => new HeaderTenantResolver($this->tenantRegistry, 'X-Custom-Tenant'),
        ];

        $chainResolver = new ChainTenantResolver(
            $resolvers,
            ['blocked_header', 'allowed_header'], // blocked_header comes first but should be skipped
            false,
            ['X-Tenant-Id'], // Only allow X-Tenant-Id
            $this->logger
        );

        $request = new Request();
        $request->headers->set('X-Tenant-Id', 'allowed-tenant');
        $request->headers->set('X-Custom-Tenant', 'blocked-tenant');

        $result = $chainResolver->resolveTenant($request);

        // Should return allowed header result, blocked header should be skipped
        $this->assertSame($allowedTenant, $result);
    }

    public function testStrictModeAmbiguityDetection(): void
    {
        $tenant1 = $this->createMockTenant('tenant1', 1);
        $tenant2 = $this->createMockTenant('tenant2', 2);

        $this->tenantRegistry
            ->method('getBySlug')
            ->willReturnMap([
                ['tenant1', $tenant1],
                ['tenant2', $tenant2],
            ]);

        $resolvers = [
            'header' => new HeaderTenantResolver($this->tenantRegistry, 'X-Tenant-Id'),
            'query' => new QueryTenantResolver($this->tenantRegistry, 'tenant'),
        ];

        $chainResolver = new ChainTenantResolver(
            $resolvers,
            ['header', 'query'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request(['tenant' => 'tenant2']);
        $request->headers->set('X-Tenant-Id', 'tenant1');

        $this->expectException(AmbiguousTenantResolutionException::class);
        $this->expectExceptionMessage('Ambiguous tenant resolution: resolvers header, query returned different tenants: tenant1, tenant2');

        $chainResolver->resolveTenant($request);
    }

    public function testStrictModeConsensusSuccess(): void
    {
        $tenant = $this->createMockTenant('same-tenant', 1);

        $this->tenantRegistry
            ->method('getBySlug')
            ->willReturn($tenant);

        $resolvers = [
            'header' => new HeaderTenantResolver($this->tenantRegistry, 'X-Tenant-Id'),
            'query' => new QueryTenantResolver($this->tenantRegistry, 'tenant'),
        ];

        $chainResolver = new ChainTenantResolver(
            $resolvers,
            ['header', 'query'],
            true, // strict mode
            [],
            $this->logger
        );

        $request = new Request(['tenant' => 'same-tenant']);
        $request->headers->set('X-Tenant-Id', 'same-tenant');

        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testStrictModeNoResultsThrowsException(): void
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

    public function testNonStrictModeReturnsNullOnNoResults(): void
    {
        $this->tenantRegistry
            ->method('getBySlug')
            ->willThrowException(new \Exception('Tenant not found'));

        $resolvers = [
            'header' => new HeaderTenantResolver($this->tenantRegistry, 'X-Tenant-Id'),
            'query' => new QueryTenantResolver($this->tenantRegistry, 'tenant'),
        ];

        $chainResolver = new ChainTenantResolver(
            $resolvers,
            ['header', 'query'],
            false, // non-strict mode
            [],
            $this->logger
        );

        $request = new Request(['tenant' => 'non-existent']);
        $request->headers->set('X-Tenant-Id', 'non-existent');

        $result = $chainResolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testComplexScenarioWithMultipleResolvers(): void
    {
        $tenant = $this->createMockTenant('found-tenant', 1);

        $resolver1 = $this->createMockResolver(null);
        $resolver2 = $this->createMockResolver(null);
        $resolver3 = $this->createMockResolver($tenant);
        $resolver4 = $this->createMockResolver(null);

        $chainResolver = new ChainTenantResolver(
            [
                'first' => $resolver1,
                'second' => $resolver2,
                'third' => $resolver3,
                'fourth' => $resolver4,
            ],
            ['first', 'second', 'third', 'fourth'],
            false,
            [],
            $this->logger
        );

        $request = new Request();
        $result = $chainResolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
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
