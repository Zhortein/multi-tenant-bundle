<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\QueryTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\QueryTenantResolver
 */
final class QueryTenantResolverTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private QueryTenantResolver $resolver;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->resolver = new QueryTenantResolver($this->tenantRegistry, 'tenant');
    }

    public function testResolveTenantFromQueryParameter(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = new Request(['tenant' => 'test-tenant']);

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithCustomParameterName(): void
    {
        $resolver = new QueryTenantResolver($this->tenantRegistry, 'custom_param');
        $tenant = $this->createMock(TenantInterface::class);
        $request = new Request(['custom_param' => 'test-tenant']);

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $result = $resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantReturnsNullWhenParameterMissing(): void
    {
        $request = new Request();

        $this->tenantRegistry
            ->expects($this->never())
            ->method('getBySlug');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveTenantReturnsNullWhenParameterEmpty(): void
    {
        $request = new Request(['tenant' => '']);

        $this->tenantRegistry
            ->expects($this->never())
            ->method('getBySlug');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveTenantReturnsNullWhenParameterNotString(): void
    {
        $request = new Request(['tenant' => 123]);

        $this->tenantRegistry
            ->expects($this->never())
            ->method('getBySlug');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveTenantReturnsNullWhenTenantNotFound(): void
    {
        $request = new Request(['tenant' => 'non-existent']);

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('non-existent')
            ->willThrowException(new TenantNotFoundException('Tenant not found'));

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testGetParameterName(): void
    {
        $this->assertSame('tenant', $this->resolver->getParameterName());

        $customResolver = new QueryTenantResolver($this->tenantRegistry, 'custom');
        $this->assertSame('custom', $customResolver->getParameterName());
    }
}
