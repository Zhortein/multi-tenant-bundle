<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

interface StorageLocationBindingInterface
{
    /** Derive from this backend's effective target, without I/O, secrets or renewable credentials. */
    public function identity(ObjectStorageBackendInterface $backend): PhysicalStorageIdentity;
}
