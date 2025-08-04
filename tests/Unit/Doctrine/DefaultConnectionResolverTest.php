<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\DefaultConnectionResolver;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Doctrine\DefaultConnectionResolver
 */
final class DefaultConnectionResolverTest extends TestCase
{
    public function testReturnsEmptyArrayWhenNoDefaultParameters(): void
    {
        $resolver = new DefaultConnectionResolver();
        $tenant = $this->createMock(TenantInterface::class);

        $result = $resolver->resolveParameters($tenant);

        $this->assertSame([], $result);
    }

    public function testReturnsDefaultParameters(): void
    {
        $defaultParams = [
            'host' => 'localhost',
            'port' => 5432,
            'user' => 'postgres',
            'password' => 'secret',
            'driver' => 'pdo_pgsql',
        ];

        $resolver = new DefaultConnectionResolver($defaultParams);
        $tenant = $this->createMock(TenantInterface::class);

        $result = $resolver->resolveParameters($tenant);

        $this->assertSame($defaultParams, $result);
    }

    public function testIgnoresTenantForSharedDatabaseApproach(): void
    {
        $defaultParams = ['dbname' => 'shared_db'];
        $resolver = new DefaultConnectionResolver($defaultParams);

        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getSlug')->willReturn('tenant-1');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getSlug')->willReturn('tenant-2');

        $result1 = $resolver->resolveParameters($tenant1);
        $result2 = $resolver->resolveParameters($tenant2);

        $this->assertSame($defaultParams, $result1);
        $this->assertSame($defaultParams, $result2);
        $this->assertSame($result1, $result2);
    }
}
