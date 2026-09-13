<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

final readonly class ObjectAuditPage
{
    /** @param list<ObjectObservation> $observations */
    public function __construct(public array $observations, public ?string $nextCursor)
    {
    }
}
