<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\OperationOutcome;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

/** Trusted synchronous factory; construction and inventory never invoke it. */
final class StorageLocationRegistration
{
    private ?StorageLocation $resolved = null;

    /** @param array<array-key, string> $allowedTenants
     * @param \Closure(): StorageLocation $factory
     */
    public function __construct(public readonly StorageLocationDescriptor $descriptor, public readonly array $allowedTenants, private readonly \Closure $factory)
    {
        if ([] === $allowedTenants || !array_is_list($allowedTenants) || count(array_unique($allowedTenants)) !== count($allowedTenants)
            || (in_array('*', $allowedTenants, true) && ['*'] !== $allowedTenants)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        foreach ($allowedTenants as $id) {
            if ('*' !== $id) {
                Validation::tenantId($id);
            }
        }
    }

    public function allows(TenantInterface $tenant): bool
    {
        $id = Validation::tenantId($tenant->getId());

        return ['*'] === $this->allowedTenants || in_array($id, $this->allowedTenants, true);
    }

    public function resolve(): StorageLocation
    {
        try {
            $location = $this->resolved ?? ($this->factory)();
            if ($location->id !== $this->descriptor->locationId || $location->allowedTenants !== $this->allowedTenants
                || ($this->descriptor->auditListing && !$location->backend instanceof AuditListingBackendInterface)
                || ($this->descriptor->identityObservation && !$location->backend instanceof ObjectIdentityBackendInterface)) {
                throw new \RuntimeException();
            }

            return $this->resolved = $location;
        } catch (\Throwable) {
            throw new ObjectStorageBackendException(OperationOutcome::NOT_APPLIED);
        }
    }
}
