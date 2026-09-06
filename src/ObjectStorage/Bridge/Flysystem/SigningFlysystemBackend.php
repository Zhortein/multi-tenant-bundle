<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use League\Flysystem\FilesystemOperator;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** Signing is an explicit capability, never inferred from FilesystemOperator. */
final class SigningFlysystemBackend extends FlysystemBackend implements TemporaryObjectUrlBackendInterface
{
    public function __construct(
        FilesystemOperator $filesystem,
        PhysicalStorageIdentity $physicalIdentity,
        KeysetListingInterface $listing,
        private readonly TemporaryObjectUrlBackendInterface $signer,
        ?ExistenceCheckerInterface $existence = null,
    ) {
        parent::__construct($filesystem, $physicalIdentity, $listing, $existence);
    }

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        QualifiedKey::validate($qualifiedKey);
        if ($expiresAt <= new \DateTimeImmutable() || $expiresAt > new \DateTimeImmutable('+86400 seconds')) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        try {
            return $this->signer->temporaryUrl($qualifiedKey, $expiresAt);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }
}
