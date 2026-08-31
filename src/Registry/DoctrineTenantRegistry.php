<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Registry;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantIdentifierException;

/**
 * Doctrine-based tenant registry.
 *
 * Loads tenants from the database using Doctrine ORM.
 */
final readonly class DoctrineTenantRegistry implements TenantRegistryInterface
{
    /**
     * @param class-string<TenantInterface> $tenantEntityClass
     */
    public function __construct(
        private EntityManagerInterface $em,
        private string $tenantEntityClass,
    ) {
    }

    public function getAll(): array
    {
        $repository = $this->em->getRepository($this->tenantEntityClass);

        /** @var TenantInterface[] $tenants */
        $tenants = $repository->findAll();

        return $tenants;
    }

    public function getBySlug(string $slug): TenantInterface
    {
        $tenant = $this->findBySlug($slug);

        if (null === $tenant) {
            throw new \RuntimeException(sprintf('Tenant with slug `%s` not found.', $slug));
        }

        return $tenant;
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        $repository = $this->em->getRepository($this->tenantEntityClass);
        $tenant = $repository->findOneBy(['slug' => $slug]);

        return $tenant instanceof TenantInterface ? $tenant : null;
    }

    public function findById(string|int $id): ?TenantInterface
    {
        $metadata = $this->em->getClassMetadata($this->tenantEntityClass);
        $mapping = $metadata->getFieldMapping($metadata->getSingleIdentifierFieldName());
        $type = $mapping['type'] ?? null;
        $value = (string) $id;
        if ('' === trim($value)
            || (in_array($type, [Types::INTEGER, Types::BIGINT, Types::SMALLINT], true) && !ctype_digit($value))
            || (in_array($type, [Types::GUID, 'uuid'], true) && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value))) {
            throw new InvalidTenantIdentifierException(sprintf('Tenant identifier "%s" is incompatible with the configured tenant mapping.', $value));
        }

        $repository = $this->em->getRepository($this->tenantEntityClass);
        $tenant = $repository->find($id);

        return $tenant instanceof TenantInterface ? $tenant : null;
    }

    public function hasSlug(string $slug): bool
    {
        return null !== $this->findBySlug($slug);
    }
}
