<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

interface ObjectIdentityObserverInterface
{
    /** Null envelope means an existing historical object. Missing objects throw OBJECT_NOT_FOUND. */
    public function observeIdentity(string $qualifiedKey): BackendIdentityObservation;
}
