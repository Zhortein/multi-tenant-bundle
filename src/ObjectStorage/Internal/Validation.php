<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Internal;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

/** @internal */
final class Validation
{
    public static function nonEmptyString(mixed $value): string
    {
        if (!is_string($value) || '' === trim($value)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }

        return $value;
    }

    public static function identifier(string $value): void
    {
        if (1 !== preg_match('/\\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\\z/D', $value)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
    }

    public static function opaque(string $value): void
    {
        if (1 !== preg_match('/\\A[a-f0-9]{64}\\z/D', $value)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
    }

    public static function tenantId(string|int $value): string
    {
        $id = (string) $value;
        if ('' === trim($id) || '*' === $id) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }

        return $id;
    }
}
