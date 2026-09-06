<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

final readonly class TemporaryObjectUrl
{
    public function __construct(public string $url, public \DateTimeImmutable $expiresAt)
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || 'https' !== ($parts['scheme'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\\x00-\\x20\\x7f]/', $url)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }
}
