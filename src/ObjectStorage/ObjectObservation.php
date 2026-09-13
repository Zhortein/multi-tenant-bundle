<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

final readonly class ObjectObservation
{
    public function __construct(public string $observationId, public ObjectObservationState $state, public ?StoredObjectReference $reference = null, public ?LogicalObjectIdentity $identity = null, public ?ObjectMetadata $metadata = null)
    {
        Internal\Validation::opaque($observationId);
        $redacted = in_array($state, [ObjectObservationState::FOREIGN, ObjectObservationState::INVALID_REFERENCE], true);
        if ((ObjectObservationState::VERIFIED === $state) !== (null !== $identity)
            || ($redacted && (null !== $reference || null !== $metadata))
            || (!$redacted && null === $reference)
            || (null !== $identity && (null === $reference || !$identity->belongsToNamespace($reference->tenantNamespace)))) {
            throw new Exception\ObjectStorageException(Exception\ObjectStorageError::INVALID_ARGUMENT);
        }
    }
}
