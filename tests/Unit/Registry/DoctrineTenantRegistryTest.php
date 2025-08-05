<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\DoctrineTenantRegistry;

/**
 * @covers \Zhortein\MultiTenantBundle\Registry\DoctrineTenantRegistry
 */
final class DoctrineTenantRegistryTest extends TestCase
{
    private DoctrineTenantRegistry $registry;
    private EntityManagerInterface $entityManager;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with('App\\Entity\\Tenant')
            ->willReturn($this->repository);

        $this->registry = new DoctrineTenantRegistry($this->entityManager, 'App\\Entity\\Tenant');
    }

    public function testGetAllReturnsAllTenants(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant2 = $this->createMock(TenantInterface::class);

        $this->repository->method('findAll')->willReturn([$tenant1, $tenant2]);

        $result = $this->registry->getAll();

        $this->assertSame([$tenant1, $tenant2], $result);
    }

    public function testGetBySlugReturnsTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->repository->method('findOneBy')
            ->with(['slug' => 'test-tenant'])
            ->willReturn($tenant);

        $result = $this->registry->getBySlug('test-tenant');

        $this->assertSame($tenant, $result);
    }

    public function testGetBySlugThrowsExceptionWhenNotFound(): void
    {
        $this->repository->method('findOneBy')
            ->with(['slug' => 'non-existent'])
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant with slug `non-existent` not found.');

        $this->registry->getBySlug('non-existent');
    }

    public function testGetBySlugThrowsExceptionWhenWrongType(): void
    {
        $this->repository->method('findOneBy')
            ->with(['slug' => 'test-tenant'])
            ->willReturn(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant with slug `test-tenant` not found.');

        $this->registry->getBySlug('test-tenant');
    }

    public function testHasSlugReturnsTrueWhenTenantExists(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->repository->method('findOneBy')
            ->with(['slug' => 'test-tenant'])
            ->willReturn($tenant);

        $result = $this->registry->hasSlug('test-tenant');

        $this->assertTrue($result);
    }

    public function testHasSlugReturnsFalseWhenTenantDoesNotExist(): void
    {
        $this->repository->method('findOneBy')
            ->with(['slug' => 'non-existent'])
            ->willReturn(null);

        $result = $this->registry->hasSlug('non-existent');

        $this->assertFalse($result);
    }

    public function testHasSlugReturnsFalseWhenExceptionThrown(): void
    {
        $this->repository->method('findOneBy')
            ->with(['slug' => 'test-tenant'])
            ->willReturn(new \stdClass());

        $result = $this->registry->hasSlug('test-tenant');

        $this->assertFalse($result);
    }
}
