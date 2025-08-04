<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver
 */
final class HeaderTenantResolverTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private HeaderTenantResolver $resolver;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->resolver = new HeaderTenantResolver($this->tenantRegistry, 'X-Tenant-Slug');
    }

    public function testResolveWithValidHeader(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = new Request();
        $request->headers->set('X-Tenant-Slug', 'acme');

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testResolveWithMissingHeader(): void
    {
        $request = new Request();

        $this->tenantRegistry
            ->expects($this->never())
            ->method('getBySlug');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveWithEmptyHeader(): void
    {
        $request = new Request();
        $request->headers->set('X-Tenant-Slug', '');

        $this->tenantRegistry
            ->expects($this->never())
            ->method('getBySlug');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveWithNonExistentTenant(): void
    {
        $request = new Request();
        $request->headers->set('X-Tenant-Slug', 'nonexistent');

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('nonexistent')
            ->willThrowException(new TenantNotFoundException('Tenant not found'));

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testResolveWithCustomHeaderName(): void
    {
        $resolver = new HeaderTenantResolver($this->tenantRegistry, 'X-Custom-Tenant');
        $tenant = $this->createMock(TenantInterface::class);
        $request = new Request();
        $request->headers->set('X-Custom-Tenant', 'acme');

        $this->tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $result = $resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testGetHeaderName(): void
    {
        $this->assertSame('X-Tenant-Slug', $this->resolver->getHeaderName());
    }

    public function testGetHeaderNameWithCustomName(): void
    {
        $resolver = new HeaderTenantResolver($this->tenantRegistry, 'X-Custom-Header');
        $this->assertSame('X-Custom-Header', $resolver->getHeaderName());
    }
}
