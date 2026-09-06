<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

final readonly class ObjectStorageRegistry
{
    /** @var array<string, StorageLocation> */
    private array $locations;

    /** @param iterable<StorageLocation> $locations
     * @param array<string, string> $providers logical provider => active generation ID
     */
    public function __construct(iterable $locations, private array $providers)
    {
        $indexed = [];
        foreach ($locations as $location) {
            if (isset($indexed[$location->id])) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
            }
            $indexed[$location->id] = $location;
        }
        $this->locations = $indexed;
        foreach ($providers as $provider => $locationId) {
            Validation::identifier($provider);
            $this->location($locationId);
        }
    }

    public function location(string $id): StorageLocation
    {
        return $this->locations[$id] ?? throw new ObjectStorageException(ObjectStorageError::UNKNOWN_LOCATION);
    }

    public function forProvider(string $provider): StorageLocation
    {
        return $this->location($this->providers[$provider] ?? throw new ObjectStorageException(ObjectStorageError::UNKNOWN_PROVIDER));
    }
}
