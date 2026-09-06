<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

final readonly class StorageLocation
{
    /** @param list<string> $allowedTenants Immutable IDs or the standalone wildcard. */
    public function __construct(
        public string $id,
        public ObjectStorageBackendInterface $backend,
        public StorageLocationBindingInterface $binding,
        public array $allowedTenants,
        public bool $temporaryUrls = false,
    ) {
        Validation::identifier($id);
        if ([] === $allowedTenants || count(array_unique($allowedTenants)) !== count($allowedTenants)
            || (in_array('*', $allowedTenants, true) && ['*'] !== $allowedTenants)
            || ($temporaryUrls && !$backend instanceof TemporaryObjectUrlBackendInterface)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        foreach ($allowedTenants as $tenantId) {
            if ('*' !== $tenantId) {
                Validation::tenantId($tenantId);
            }
        }
    }

    public function allows(TenantInterface $tenant): bool
    {
        $id = Validation::tenantId($tenant->getId());

        return ['*'] === $this->allowedTenants || in_array($id, $this->allowedTenants, true);
    }

    public function fingerprint(): string
    {
        try {
            return $this->binding->identity($this->backend)->fingerprint();
        } catch (\Throwable) {
            throw new Exception\ObjectStorageBackendException(Exception\OperationOutcome::NOT_APPLIED);
        }
    }
}
