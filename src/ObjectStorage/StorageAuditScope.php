<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Explicit location scope, without allocating an object. Never an authorization token. */
final readonly class StorageAuditScope
{
    public function __construct(public string $locationId, public string $locationBinding, public string $tenantNamespace, public string $registryRevision)
    {
        Internal\Validation::identifier($locationId);
        Internal\Validation::opaque($locationBinding);
        Internal\Validation::opaque($tenantNamespace);
        Internal\Validation::opaque($registryRevision);
    }
}
