<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use League\Flysystem\FilesystemOperator;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\OperationOutcome;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationBindingInterface;

/**
 * Optional synchronous bridge. The constructor is a trusted composition boundary:
 * the operator, identity and capabilities MUST describe the same immutable target.
 * Arbitrary operators cannot be introspected through Flysystem's public API.
 */
class FlysystemBackend implements ObjectStorageBackendInterface, StorageLocationBindingInterface
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly PhysicalStorageIdentity $physicalIdentity,
        private readonly KeysetListingInterface $listing,
        private readonly ?ExistenceCheckerInterface $existence = null,
    ) {
    }

    public function identity(ObjectStorageBackendInterface $backend): PhysicalStorageIdentity
    {
        if ($backend !== $this) {
            throw new ObjectStorageBackendException(OperationOutcome::NOT_APPLIED);
        }

        return $this->physicalIdentity;
    }

    public function write(string $qualifiedKey, string $content): void
    {
        QualifiedKey::validate($qualifiedKey);
        try {
            $this->filesystem->write($qualifiedKey, $content, ['visibility' => 'private']);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function writeFromStream(string $qualifiedKey, ObjectStreamSourceInterface $source): void
    {
        QualifiedKey::validate($qualifiedKey);
        // Flysystem rewinds seekable input. Spool only unread caller bytes and never
        // expose the caller's resource. Memory is capped; overflow uses a temporary file.
        $spool = @fopen('php://temp/maxmemory:65536', 'w+b');
        if (false === $spool) {
            throw new ObjectStorageBackendException(OperationOutcome::NOT_APPLIED);
        }
        $entered = false;
        try {
            while (!$this->atEnd($source)) {
                $chunk = $source->readChunk();
                if (strlen($chunk) > 65536 || ('' === $chunk && !$this->atEnd($source))) {
                    throw new \RuntimeException();
                }
                while ('' !== $chunk) {
                    $written = @fwrite($spool, $chunk);
                    if (false === $written || 0 === $written) {
                        throw new \RuntimeException();
                    }
                    $chunk = substr($chunk, $written);
                }
            }
            if (!rewind($spool)) {
                throw new \RuntimeException();
            }
            $entered = true;
            $this->filesystem->writeStream($qualifiedKey, $spool, ['visibility' => 'private']);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException($entered ? OperationOutcome::UNKNOWN : OperationOutcome::NOT_APPLIED);
        } finally {
            if (is_resource($spool)) {
                fclose($spool);
            }
        }
    }

    /** @phpstan-impure Stream position can change between calls. */
    private function atEnd(ObjectStreamSourceInterface $source): bool
    {
        return $source->eof();
    }

    public function read(string $qualifiedKey): string
    {
        $this->requireObject($qualifiedKey);
        try {
            return $this->filesystem->read($qualifiedKey);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function readToStream(string $qualifiedKey, ObjectStreamDestinationInterface $destination): void
    {
        $this->requireObject($qualifiedKey);
        $stream = null;
        $delivered = false;
        try {
            $stream = $this->filesystem->readStream($qualifiedKey);
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if (false === $chunk || ('' === $chunk && !feof($stream))) {
                    throw new \RuntimeException();
                }
                if ('' !== $chunk) {
                    $destination->writeChunk($chunk);
                    $delivered = true;
                }
            }
        } catch (\Throwable) {
            throw new ObjectStorageBackendException($delivered ? OperationOutcome::PARTIAL : OperationOutcome::UNKNOWN);
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable) {
                throw new ObjectStorageBackendException($delivered ? OperationOutcome::PARTIAL : OperationOutcome::UNKNOWN);
            }
        }
    }

    public function exists(string $qualifiedKey): bool
    {
        QualifiedKey::validate($qualifiedKey);
        try {
            return null !== $this->existence ? $this->existence->exists($qualifiedKey) : $this->filesystem->fileExists($qualifiedKey);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    private function requireObject(string $key): void
    {
        if (!$this->exists($key)) {
            throw new ObjectStorageException(ObjectStorageError::OBJECT_NOT_FOUND);
        }
    }

    public function metadata(string $qualifiedKey): ObjectMetadata
    {
        $this->requireObject($qualifiedKey);
        try {
            return new ObjectMetadata($this->filesystem->fileSize($qualifiedKey), new \DateTimeImmutable('@'.$this->filesystem->lastModified($qualifiedKey)));
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        QualifiedKey::page($tenantPrefix, $limit, $afterKey);
        try {
            return $this->listing->list($tenantPrefix, $limit, $afterKey);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        $this->transfer($sourceKey, $destinationKey, false);
    }

    public function move(string $sourceKey, string $destinationKey): void
    {
        $this->transfer($sourceKey, $destinationKey, true);
    }

    private function transfer(string $source, string $destination, bool $move): void
    {
        QualifiedKey::validate($source);
        QualifiedKey::validate($destination);
        if (substr($source, 0, 76) !== substr($destination, 0, 76) || $source === $destination) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $this->requireObject($source);
        try {
            if ($move) {
                $this->filesystem->move($source, $destination, ['visibility' => 'private']);
            } else {
                $this->filesystem->copy($source, $destination, ['visibility' => 'private']);
            }
        } catch (\Throwable) {
            // Includes a failed deletion after a successful adapter copy. The
            // generic operator cannot prove which step applied. Never compensate.
            throw new ObjectStorageBackendException();
        }
    }

    public function delete(string $qualifiedKey): void
    {
        QualifiedKey::validate($qualifiedKey);
        try {
            $this->filesystem->delete($qualifiedKey);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }
}
