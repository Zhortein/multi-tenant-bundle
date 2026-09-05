<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

interface TemporaryObjectUrlBackendInterface
{
    /** Sign for this exact expiry; never return a permanent/public URL or a later expiration. */
    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl;
}
