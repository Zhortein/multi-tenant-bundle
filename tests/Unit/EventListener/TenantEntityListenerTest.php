<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantEntityListener;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;

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
        $entityManager->method('getReference')->willReturn($tenant);

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
        $entityManager->method('contains')->with($existingTenant)->willReturn(true);

        $entity
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($existingTenant);

        $entity
            ->expects($this->never())
            ->method('setTenant');

        $this->tenantContext->expects($this->once())->method('getTenant')->willReturn($existingTenant);
        $existingTenant->method('getId')->willReturn('a');

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->listener->prePersist($args);
    }

    public function testPrePersistRejectsWhenNoTenantInContext(): void
    {
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $entity->expects($this->never())->method('getTenant');

        $entity
            ->expects($this->never())
            ->method('setTenant');

        $args = new PrePersistEventArgs($entity, $entityManager);
        $this->expectException(MissingTenantContextException::class);
        $this->listener->prePersist($args);
    }

    public function testPrePersistRejectsEntityOwnedByAnotherTenant(): void
    {
        $current = $this->tenant('a');
        $other = $this->tenant('b');
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entity->method('getTenant')->willReturn($other);
        $this->tenantContext->method('getTenant')->willReturn($current);
        $this->expectException(TenantMismatchException::class);
        $this->listener->prePersist(new PrePersistEventArgs($entity, $this->createMock(EntityManagerInterface::class)));
    }

    public function testPreUpdateRejectsTenantChange(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entity->method('getTenant')->willReturn($tenantB);
        $this->tenantContext->method('getTenant')->willReturn($tenantA);
        $changes = ['tenant' => [$tenantA, $tenantB]];
        $this->expectException(TenantMismatchException::class);
        $this->listener->preUpdate(new PreUpdateEventArgs($entity, $this->createMock(EntityManagerInterface::class), $changes));
    }

    public function testPreUpdateRequiresContext(): void
    {
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $changes = [];
        $this->expectException(MissingTenantContextException::class);
        $this->listener->preUpdate(new PreUpdateEventArgs($entity, $this->createMock(EntityManagerInterface::class), $changes));
    }

    public function testPreRemoveRejectsOtherTenantAndRequiresContext(): void
    {
        $entity = $this->createMock(TenantOwnedEntityInterface::class);
        $entity->method('getTenant')->willReturn($this->tenant('b'));
        $this->tenantContext->method('getTenant')->willReturn($this->tenant('a'));
        $this->expectException(TenantMismatchException::class);
        $this->listener->preRemove(new PreRemoveEventArgs($entity, $this->createMock(EntityManagerInterface::class)));
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

    private function tenant(string $id): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}
