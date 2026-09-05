<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

final readonly class ObjectMetadata
{
    public function __construct(public int $size, public ?\DateTimeImmutable $lastModified = null)
    {
        if ($size < 0) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }
}
