<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver
 */
final class SubdomainTenantResolverTest extends TestCase
{
    private SubdomainTenantResolver $resolver;
    private EntityManagerInterface $entityManager;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with('App\\Entity\\Tenant')
            ->willReturn($this->repository);

        $this->resolver = new SubdomainTenantResolver(
            $this->entityManager,
            'App\\Entity\\Tenant',
            'example.com'
        );
    }

    public function testResolvesTenantFromSubdomain(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('http://tenant-slug.example.com/some/path');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'tenant-slug'])
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenTenantNotFound(): void
    {
        $request = Request::create('http://non-existent.example.com/some/path');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'non-existent'])
            ->willReturn(null);

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForWrongBaseDomain(): void
    {
        $request = Request::create('http://tenant-slug.other-domain.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForWwwSubdomain(): void
    {
        $request = Request::create('http://www.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForApiSubdomain(): void
    {
        $request = Request::create('http://api.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForAdminSubdomain(): void
    {
        $request = Request::create('http://admin.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForMailSubdomain(): void
    {
        $request = Request::create('http://mail.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForFtpSubdomain(): void
    {
        $request = Request::create('http://ftp.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForBaseDomain(): void
    {
        $request = Request::create('http://example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testReturnsNullForNestedSubdomain(): void
    {
        $request = Request::create('http://sub.tenant-slug.example.com/some/path');

        $result = $this->resolver->resolveTenant($request);

        $this->assertNull($result);
    }

    public function testHandlesHttpsRequests(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('https://tenant-slug.example.com/some/path');

        $this->repository->method('findOneBy')
            ->with(['slug' => 'tenant-slug'])
            ->willReturn($tenant);

        $result = $this->resolver->resolveTenant($request);

        $this->assertSame($tenant, $result);
    }
}
