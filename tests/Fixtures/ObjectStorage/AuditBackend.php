<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\AuditListingBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendIdentityObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;

final class AuditBackend extends InstrumentedBackend implements AuditListingBackendInterface, ObjectIdentityBackendInterface
{
    public static int $constructions = 0;
    public array $envelopes = [];
    public array $observationFailures = [];

    public function __construct(string $target = 'audit-target')
    {
        ++self::$constructions;
        parent::__construct($target);
    }

    public function auditList(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        return $this->list($tenantPrefix, $limit, $afterKey);
    }

    public function write(string $qualifiedKey, string $content): void
    {
        parent::write($qualifiedKey, $content);
        unset($this->envelopes[$qualifiedKey]);
    }

    public function writeWithIdentity(string $qualifiedKey, string $content, string $envelope): void
    {
        $this->write($qualifiedKey, $content);
        $this->envelopes[$qualifiedKey] = $envelope;
    }

    public function writeFromStreamWithIdentity(string $qualifiedKey, ObjectStreamSourceInterface $source, string $envelope): void
    {
        parent::writeFromStream($qualifiedKey, $source);
        $this->envelopes[$qualifiedKey] = $envelope;
    }

    public function observeIdentity(string $qualifiedKey): BackendIdentityObservation
    {
        $this->record('observeIdentity', $qualifiedKey);
        if (isset($this->observationFailures[$qualifiedKey])) {
            throw $this->observationFailures[$qualifiedKey];
        }
        if (!isset($this->objects[$qualifiedKey])) {
            throw new ObjectStorageException(ObjectStorageError::OBJECT_NOT_FOUND);
        }

        return new BackendIdentityObservation(new ObjectMetadata(strlen($this->objects[$qualifiedKey])), $this->envelopes[$qualifiedKey] ?? null);
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        parent::copy($sourceKey, $destinationKey);
        unset($this->envelopes[$destinationKey]);
        if (isset($this->envelopes[$sourceKey])) {
            $this->envelopes[$destinationKey] = $this->envelopes[$sourceKey];
        }
    }

    public function move(string $sourceKey, string $destinationKey): void
    {
        parent::move($sourceKey, $destinationKey);
        unset($this->envelopes[$destinationKey]);
        if (isset($this->envelopes[$sourceKey])) {
            $this->envelopes[$destinationKey] = $this->envelopes[$sourceKey];
        }
        unset($this->envelopes[$sourceKey]);
    }

    public function delete(string $qualifiedKey): void
    {
        parent::delete($qualifiedKey);
        unset($this->envelopes[$qualifiedKey]);
    }
}
