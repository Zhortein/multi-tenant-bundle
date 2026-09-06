<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

/** @internal Additional defense for direct technical callers; never resolves a tenant. */
final class QualifiedKey
{
    public static function validate(string $key, bool $prefix = false): void
    {
        if (1 !== preg_match($prefix ? '/\Aobjects\/v1\/[a-f0-9]{64}\/\z/D' : '/\Aobjects\/v1\/[a-f0-9]{64}\/[a-f0-9]{64}\z/D', $key)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    public static function page(string $prefix, int $limit, ?string $after): void
    {
        self::validate($prefix, true);
        if ($limit < 1 || $limit > 1000) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        if (null !== $after) {
            self::validate($after);
            if (!str_starts_with($after, $prefix)) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
            }
        }
    }
}
