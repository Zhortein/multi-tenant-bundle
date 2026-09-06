<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\ObjectStorage\Minio;

use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\FlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationBindingInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** Test instrumentation in front of real MinIO. No recorded paths or credentials. */
final class RecordingBackend implements ObjectStorageBackendInterface, StorageLocationBindingInterface, TemporaryObjectUrlBackendInterface
{
    public int $calls = 0;

    public function __construct(public FlysystemBackend $delegate)
    {
    }

    public function identity(ObjectStorageBackendInterface $backend): PhysicalStorageIdentity
    {
        if ($backend !== $this) {
            throw new \LogicException('Incorrect test binding.');
        }

        return $this->delegate->identity($this->delegate);
    }

    public function write(string $qualifiedKey, string $content): void
    {
        ++$this->calls;
        $this->delegate->write($qualifiedKey, $content);
    }

    public function writeFromStream(string $qualifiedKey, ObjectStreamSourceInterface $source): void
    {
        ++$this->calls;
        $this->delegate->writeFromStream($qualifiedKey, $source);
    }

    public function read(string $qualifiedKey): string
    {
        ++$this->calls;

        return $this->delegate->read($qualifiedKey);
    }

    public function readToStream(string $qualifiedKey, ObjectStreamDestinationInterface $destination): void
    {
        ++$this->calls;
        $this->delegate->readToStream($qualifiedKey, $destination);
    }

    public function exists(string $qualifiedKey): bool
    {
        ++$this->calls;

        return $this->delegate->exists($qualifiedKey);
    }

    public function metadata(string $qualifiedKey): ObjectMetadata
    {
        ++$this->calls;

        return $this->delegate->metadata($qualifiedKey);
    }

    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        ++$this->calls;

        return $this->delegate->list($tenantPrefix, $limit, $afterKey);
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        ++$this->calls;
        $this->delegate->copy($sourceKey, $destinationKey);
    }

    public function move(string $sourceKey, string $destinationKey): void
    {
        ++$this->calls;
        $this->delegate->move($sourceKey, $destinationKey);
    }

    public function delete(string $qualifiedKey): void
    {
        ++$this->calls;
        $this->delegate->delete($qualifiedKey);
    }

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        ++$this->calls;
        if (!$this->delegate instanceof TemporaryObjectUrlBackendInterface) {
            throw new \LogicException('Unsigned test backend.');
        }

        return $this->delegate->temporaryUrl($qualifiedKey, $expiresAt);
    }
}
