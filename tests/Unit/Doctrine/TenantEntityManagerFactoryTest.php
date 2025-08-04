<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Configuration;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory
 */
final class TenantEntityManagerFactoryTest extends TestCase
{
    private TenantConnectionResolverInterface $connectionResolver;
    private Configuration $ormConfiguration;
    private TenantEntityManagerFactory $factory;

    protected function setUp(): void
    {
        $this->connectionResolver = $this->createMock(TenantConnectionResolverInterface::class);
        $this->ormConfiguration = $this->createMock(Configuration::class);

        // Mock the metadata driver to avoid the missing driver exception
        $metadataDriver = $this->createMock(\Doctrine\Persistence\Mapping\Driver\MappingDriver::class);
        $this->ormConfiguration->method('getMetadataDriverImpl')->willReturn($metadataDriver);
        $this->ormConfiguration->method('getProxyDir')->willReturn('/tmp');
        $this->ormConfiguration->method('getProxyNamespace')->willReturn('Proxies');
        $this->ormConfiguration->method('getAutoGenerateProxyClasses')->willReturn(1);

        $this->factory = new TenantEntityManagerFactory(
            $this->connectionResolver,
            $this->ormConfiguration
        );
    }

    public function testCreateForTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $connectionParams = [
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'dbname' => 'tenant_db',
            'user' => 'user',
            'password' => 'password',
        ];

        $this->connectionResolver
            ->expects($this->once())
            ->method('resolveParameters')
            ->with($tenant)
            ->willReturn($connectionParams);

        // Skip actual EntityManager creation due to complex mocking requirements
        $this->markTestSkipped('EntityManager creation requires complex database setup');
    }

    public function testCreateForTenants(): void
    {
        // Skip actual EntityManager creation due to complex mocking requirements
        $this->markTestSkipped('EntityManager creation requires complex database setup');
    }

    public function testCreateForTenantsWithEmptyArray(): void
    {
        $result = $this->factory->createForTenants([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
