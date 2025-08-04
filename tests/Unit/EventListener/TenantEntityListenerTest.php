<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantEntityListener;

/**
 * @covers \Zhortein\MultiTenantBundle\EventListener\TenantEntityListener
 */
final class TenantEntityListenerTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantEntityListener $listener;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->listener = new TenantEntityListener($this->tenantContext);
    }

    public function testPrePersistSetsTenantOnNewEntity(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $entity
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $entity
            ->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->listener->prePersist($args);
    }

    public function testPrePersistSkipsEntityWithExistingTenant(): void
    {
        $existingTenant = $this->createMock(TenantInterface::class);
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $entity
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($existingTenant);

        $entity
            ->expects($this->never())
            ->method('setTenant');

        $this->tenantContext
            ->expects($this->never())
            ->method('getTenant');

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->listener->prePersist($args);
    }

    public function testPrePersistSkipsWhenNoTenantInContext(): void
    {
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $entity
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $entity
            ->expects($this->never())
            ->method('setTenant');

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->listener->prePersist($args);
    }

    public function testPrePersistSkipsNonTenantOwnedEntity(): void
    {
        $entity = new \stdClass();
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantContext
            ->expects($this->never())
            ->method('getTenant');

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->listener->prePersist($args);
    }
}