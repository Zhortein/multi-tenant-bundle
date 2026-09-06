<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Exception;

final class ObjectStorageBackendException extends ObjectStorageException
{
    public function __construct(
        public readonly OperationOutcome $outcome = OperationOutcome::UNKNOWN,
    ) {
        // Deliberately do not expose adapter messages, credentials or physical paths.
        parent::__construct(ObjectStorageError::BACKEND_FAILURE);
    }
}
