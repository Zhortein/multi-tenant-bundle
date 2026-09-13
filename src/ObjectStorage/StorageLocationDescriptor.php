<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Public generation IDs are immutable opaque technical identifiers, never bucket names. */
final readonly class StorageLocationDescriptor
{
    public bool $listing;

    public function __construct(public string $locationId, public string $provider, public bool $active, public bool $auditListing = false, public bool $identityObservation = false)
    {
        Internal\Validation::identifier($locationId);
        Internal\Validation::identifier($provider);
        $this->listing = true; // Mandatory in the unchanged RC11 backend contract.
    }
}
