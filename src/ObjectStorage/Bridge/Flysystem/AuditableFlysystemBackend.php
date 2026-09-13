<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use League\Flysystem\FilesystemOperator;
use Zhortein\MultiTenantBundle\ObjectStorage\AuditListingBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendIdentityObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityObserverInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** Explicit composition: the operator must atomically support Metadata and preserve it on copy/move. */
final class AuditableFlysystemBackend extends FlysystemBackend implements AuditListingBackendInterface, ObjectIdentityBackendInterface, TemporaryObjectUrlBackendInterface
{
    public const IDENTITY_METADATA = 'mtb-identity-v1';

    public function __construct(
        FilesystemOperator $filesystem,
        PhysicalStorageIdentity $physicalIdentity,
        KeysetListingInterface $listing,
        private readonly AuditListingBackendInterface $auditListing,
        private readonly ObjectIdentityObserverInterface $observer,
        ?ExistenceCheckerInterface $existence = null,
        private readonly ?TemporaryObjectUrlBackendInterface $signer = null,
    ) {
        parent::__construct($filesystem, $physicalIdentity, $listing, $existence);
    }

    public function auditList(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        return $this->auditListing->auditList($tenantPrefix, $limit, $afterKey);
    }

    public function observeIdentity(string $qualifiedKey): BackendIdentityObservation
    {
        return $this->observer->observeIdentity($qualifiedKey);
    }

    public function writeWithIdentity(string $qualifiedKey, string $content, string $envelope): void
    {
        $this->validateEnvelope($envelope);
        $this->writeWithOptions($qualifiedKey, $content, ['Metadata' => [self::IDENTITY_METADATA => $envelope]]);
    }

    public function writeFromStreamWithIdentity(string $qualifiedKey, ObjectStreamSourceInterface $source, string $envelope): void
    {
        $this->validateEnvelope($envelope);
        $this->writeStreamWithOptions($qualifiedKey, $source, ['Metadata' => [self::IDENTITY_METADATA => $envelope]]);
    }

    private function validateEnvelope(string $envelope): void
    {
        if (strlen($envelope) > 2048 || 1 !== preg_match('/\A[A-Za-z0-9_.-]+\z/D', $envelope)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        QualifiedKey::validate($qualifiedKey);
        $signer = $this->signer ?? throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);

        return $signer->temporaryUrl($qualifiedKey, $expiresAt);
    }
}
