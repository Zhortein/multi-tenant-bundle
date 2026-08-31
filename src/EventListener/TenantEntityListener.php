<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScope;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantIdentifierException;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantMappingException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;

/**
 * Doctrine event listener that automatically sets the tenant
 * on entities that implement TenantOwnedEntityInterface.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class TenantEntityListener
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private ?GlobalDoctrineScope $globalScope = null,
    ) {
    }

    /**
     * Automatically sets the tenant on new entities before persistence.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        if ($this->globalScope?->isActive()) {
            return;
        }
        $entity = $args->getObject();

        $tenantField = $this->tenantField($entity);
        if (null === $tenantField) {
            return;
        }

        $currentTenant = $this->requireCurrentTenant();
        $entityTenant = $this->entityTenant($entity, $tenantField, $args->getObjectManager());
        if (null === $entityTenant) {
            $this->setEntityTenant($entity, $tenantField, $this->managedTenant($currentTenant, $args->getObjectManager()), $args->getObjectManager());

            return;
        }

        $this->assertSameTenant($currentTenant, $entityTenant);
        if (!$args->getObjectManager()->contains($entityTenant)) {
            $this->setEntityTenant($entity, $tenantField, $this->managedTenant($currentTenant, $args->getObjectManager()), $args->getObjectManager());
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($this->globalScope?->isActive()) {
            return;
        }
        $entity = $args->getObject();
        $tenantField = $this->tenantField($entity);
        if (null === $tenantField) {
            return;
        }

        $currentTenant = $this->requireCurrentTenant();
        if ($args->hasChangedField($tenantField)) {
            $old = $args->getOldValue($tenantField);
            $new = $args->getNewValue($tenantField);
            if (!$old instanceof TenantInterface || !$new instanceof TenantInterface || $this->tenantId($old) !== $this->tenantId($new)) {
                throw new TenantMismatchException('Changing the tenant of a managed entity is forbidden.');
            }
        }

        $entityTenant = $this->entityTenant($entity, $tenantField, $args->getObjectManager());
        if (null === $entityTenant) {
            throw new InvalidTenantMappingException('A tenant-aware entity being updated has no tenant.');
        }
        $this->assertSameTenant($currentTenant, $entityTenant);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($this->globalScope?->isActive()) {
            return;
        }
        $entity = $args->getObject();
        $tenantField = $this->tenantField($entity);
        if (null === $tenantField) {
            return;
        }

        $currentTenant = $this->requireCurrentTenant();
        $entityTenant = $this->entityTenant($entity, $tenantField, $args->getObjectManager());
        if (null === $entityTenant) {
            throw new InvalidTenantMappingException('A tenant-aware entity being removed has no tenant.');
        }
        $this->assertSameTenant($currentTenant, $entityTenant);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->globalScope?->isActive()) {
            return;
        }

        $manager = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->assertScheduledWrite($entity, $manager);
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $tenantField = $this->tenantField($entity);
            if (null === $tenantField) {
                continue;
            }
            $originalTenant = $unitOfWork->getOriginalEntityData($entity)[$tenantField] ?? null;
            $currentTenant = $this->entityTenant($entity, $tenantField, $manager);
            if (!$originalTenant instanceof TenantInterface || !$currentTenant instanceof TenantInterface) {
                throw new InvalidTenantMappingException('A tenant-aware entity update has no stable original tenant.');
            }
            $this->assertSameTenant($originalTenant, $currentTenant);
            $this->assertSameTenant($this->requireCurrentTenant(), $currentTenant);
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->assertScheduledWrite($entity, $manager);
        }
    }

    private function assertScheduledWrite(object $entity, EntityManagerInterface $manager): void
    {
        $tenantField = $this->tenantField($entity);
        if (null === $tenantField) {
            return;
        }
        $entityTenant = $this->entityTenant($entity, $tenantField, $manager);
        if (null === $entityTenant) {
            throw new InvalidTenantMappingException('A scheduled tenant-aware write has no tenant.');
        }
        $this->assertSameTenant($this->requireCurrentTenant(), $entityTenant);
    }

    private function requireCurrentTenant(): TenantInterface
    {
        return $this->tenantContext->getTenant()
            ?? throw new MissingTenantContextException('A tenant context is required for tenant-aware ORM writes.');
    }

    private function tenantField(object $entity): ?string
    {
        if ($entity instanceof TenantOwnedEntityInterface) {
            return 'tenant';
        }

        $attributes = (new \ReflectionClass($entity))->getAttributes(AsTenantAware::class);
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance()->tenantField;
    }

    private function entityTenant(object $entity, string $field, EntityManagerInterface $manager): ?TenantInterface
    {
        if ($entity instanceof TenantOwnedEntityInterface && 'tenant' === $field) {
            return $entity->getTenant();
        }

        $metadata = $manager->getClassMetadata($entity::class);
        if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
            throw new InvalidTenantMappingException(sprintf('Tenant-aware entity "%s" has no mapped tenant field "%s".', $entity::class, $field));
        }
        $tenant = $metadata->getFieldValue($entity, $field);

        return $tenant instanceof TenantInterface ? $tenant : null;
    }

    private function setEntityTenant(object $entity, string $field, TenantInterface $tenant, EntityManagerInterface $manager): void
    {
        if ($entity instanceof TenantOwnedEntityInterface && 'tenant' === $field) {
            $entity->setTenant($tenant);

            return;
        }

        $manager->getClassMetadata($entity::class)->setFieldValue($entity, $field, $tenant);
    }

    private function assertSameTenant(TenantInterface $expected, TenantInterface $actual): void
    {
        if ($this->tenantId($expected) !== $this->tenantId($actual)) {
            throw new TenantMismatchException(sprintf('Entity tenant "%s" conflicts with current tenant "%s".', $this->tenantId($actual), $this->tenantId($expected)));
        }
    }

    private function tenantId(TenantInterface $tenant): string
    {
        $identifier = (string) $tenant->getId();
        if ('' === trim($identifier)) {
            throw new InvalidTenantIdentifierException('A tenant used for an ORM write has an empty identifier.');
        }

        return $identifier;
    }

    private function managedTenant(TenantInterface $tenant, EntityManagerInterface $manager): TenantInterface
    {
        $reference = $manager->getReference($tenant::class, $tenant->getId());
        if (!$reference instanceof TenantInterface) {
            throw new InvalidTenantMappingException('The configured tenant reference does not implement TenantInterface.');
        }

        return $reference;
    }
}
