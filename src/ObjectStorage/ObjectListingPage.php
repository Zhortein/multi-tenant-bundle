<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

final readonly class ObjectListingPage
{
    /** @param list<StoredObjectReference> $references */
    public function __construct(public array $references, public ?string $nextCursor = null)
    {
    }
}
