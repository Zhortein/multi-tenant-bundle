<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Technical result of one metadata read. The envelope is opaque and not yet trusted. */
final readonly class BackendIdentityObservation
{
    public function __construct(public ObjectMetadata $metadata, public ?string $envelope)
    {
    }
}
