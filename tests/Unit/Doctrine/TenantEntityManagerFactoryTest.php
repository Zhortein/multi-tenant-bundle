<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

/**
 * @covers \Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory
 */
final class TenantEntityManagerFactoryTest extends TestCase
{
    private TenantEntityManagerFactory $factory;

    protected function setUp(): void
    {
        $provider = new class implements TenantConnectionParametersProviderInterface {
            public function parametersFor(TenantConnectionState $state): array
            {
                return ['driver' => 'pdo_sqlite', 'memory' => true];
            }
        };
        $this->factory = new TenantEntityManagerFactory(
            $provider,
            ORMSetup::createAttributeMetadataConfiguration([], true),
        );
    }

    public function testCreateForTenant(): void
    {
        $entityManager = $this->factory->createForTenant((new TestTenant())->setId(1)->setSlug('a'));

        self::assertSame('pdo_sqlite', $entityManager->getConnection()->getParams()['driver']);
        $entityManager->close();
    }

    public function testCreateForTenants(): void
    {
        $managers = $this->factory->createForTenants([
            (new TestTenant())->setId(1)->setSlug('a'),
            (new TestTenant())->setId(2)->setSlug('b'),
        ]);

        self::assertSame(['a', 'b'], array_keys($managers));
        foreach ($managers as $manager) {
            $manager->close();
        }
    }

    public function testCreateForTenantsWithEmptyArray(): void
    {
        $result = $this->factory->createForTenants([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
