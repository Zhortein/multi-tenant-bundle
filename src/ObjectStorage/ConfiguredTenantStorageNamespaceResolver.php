<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

/** Server-owned mapping of immutable tenant IDs to unique random 256-bit namespaces. */
final readonly class ConfiguredTenantStorageNamespaceResolver implements TenantStorageNamespaceResolverInterface
{
    /** @param array<string|int, string> $namespaces */
    public function __construct(private array $namespaces)
    {
        foreach ($namespaces as $id => $namespace) {
            Validation::tenantId($id);
            Validation::opaque($namespace);
        }
        if (count(array_unique($namespaces)) !== count($namespaces)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    public function resolve(TenantInterface $tenant): string
    {
        return $this->namespaces[Validation::tenantId($tenant->getId())]
            ?? throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
    }
}
