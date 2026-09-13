<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

final readonly class StorageLocationInventoryPage
{
    /** @param list<StorageLocationDescriptor> $locations */
    public function __construct(public array $locations, public ?string $nextCursor, public string $registryRevision)
    {
    }
}
