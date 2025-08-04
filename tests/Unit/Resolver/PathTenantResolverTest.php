<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Resolver\PathTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\PathTenantResolver
 */
final class PathTenantResolverTest extends TestCase
{
    private PathTenantResolver $resolver;
    private EntityManagerInterface $entityManager;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with('App\\Entity\\Tenant')
            ->willReturn($this->repository);

        $this->resolver = new PathTenantResolver($this->entityManager, 'App\\Entity\\Tenant');
    }

    public function testResolvesTenantFromFirstPathSegment(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('/tenant-slug/some/path');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'tenant-slug'])
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenTenantNotFound(): void
    {
        $request = Request::create('/non-existent-tenant/some/path');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'non-existent-tenant'])
            ->willReturn(null);

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForEmptyPath(): void
    {
        $request = Request::create('/');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForRootPath(): void
    {
        $request = Request::create('');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testHandlesPathWithTrailingSlash(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('/tenant-slug/');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'tenant-slug'])
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testHandlesComplexPath(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('/tenant-slug/admin/users/123/edit?param=value');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'tenant-slug'])
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }
}
