<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantIdentifierException;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantMappingException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;

final class TenantDoctrineFilterTest extends TestCase
{
    public function testTenantAwareEntityWithoutParameterFailsClosed(): void
    {
        $this->expectException(MissingTenantContextException::class);
        $this->filter()->addFilterConstraint($this->tenantMetadata(), 'row');
    }

    public function testEmptyParameterFailsClosed(): void
    {
        $filter = $this->filter();
        $filter->setParameter('tenant_context_mode', 'tenant');
        $filter->setParameter('tenant_id', '');
        $this->expectException(InvalidTenantIdentifierException::class);
        $filter->addFilterConstraint($this->tenantMetadata(), 'row');
    }

    public function testIdentifierIncompatibleWithMappingFailsClosed(): void
    {
        $filter = $this->filter();
        $filter->setParameter('tenant_context_mode', 'tenant');
        $filter->setParameter('tenant_id', 'not-an-integer');
        $this->expectException(InvalidTenantIdentifierException::class);
        $filter->addFilterConstraint($this->tenantMetadata(), 'row');
    }

    public function testInvalidTenantMappingFailsClosed(): void
    {
        $filter = $this->filter();
        $filter->setParameter('tenant_context_mode', 'tenant');
        $filter->setParameter('tenant_id', 1);
        $this->expectException(InvalidTenantMappingException::class);
        $filter->addFilterConstraint(new ClassMetadata(InvalidTenantEntity::class), 'row');
    }

    public function testValidTenantMappingAlwaysProducesConstraint(): void
    {
        $filter = $this->filter();
        $filter->setParameter('tenant_context_mode', 'tenant');
        $filter->setParameter('tenant_id', 12);
        self::assertSame("row.tenant_id = '12'", $filter->addFilterConstraint($this->tenantMetadata(), 'row'));
    }

    public function testGlobalEntityRemainsUnaffectedWithoutTenantParameter(): void
    {
        self::assertSame('', $this->filter()->addFilterConstraint(new ClassMetadata(GlobalEntity::class), 'row'));
    }

    private function filter(): TenantDoctrineFilter
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => "'{$value}'");
        $filters = $this->createStub(FilterCollection::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getFilters')->willReturn($filters);

        return new TenantDoctrineFilter($entityManager);
    }

    /** @return ClassMetadata<TenantEntity> */
    private function tenantMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(TenantEntity::class);
        $metadata->mapField(['fieldName' => 'tenant', 'columnName' => 'tenant_id', 'type' => 'integer']);

        return $metadata;
    }
}

final class TenantEntity implements TenantOwnedEntityInterface
{
    public function getTenant(): ?TenantInterface
    {
        return null;
    }

    public function setTenant(TenantInterface $tenant): void
    {
    }
}

final class InvalidTenantEntity implements TenantOwnedEntityInterface
{
    public function getTenant(): ?TenantInterface
    {
        return null;
    }

    public function setTenant(TenantInterface $tenant): void
    {
    }
}

final class GlobalEntity
{
}
