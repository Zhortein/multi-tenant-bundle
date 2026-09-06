<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Internal;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;

/** @internal The caller's resource is never exposed to the backend or closed here. */
final class ScopedStream implements ObjectStreamSourceInterface, ObjectStreamDestinationInterface
{
    public const CHUNK_SIZE = 65536;

    private bool $active = true;

    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(mixed $stream, private readonly \Closure $guard, private readonly bool $reading)
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $this->stream = $stream;
        $mode = stream_get_meta_data($stream)['mode'];
        if ($reading ? !strpbrk($mode, 'r+') : !strpbrk($mode, 'waxc+')) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    public function invalidate(): void
    {
        $this->active = false;
        $this->stream = null;
    }

    public function readChunk(): string
    {
        $this->check(true);
        $chunk = @fread($this->stream, self::CHUNK_SIZE);
        $this->check(true);
        if (false === $chunk || ('' === $chunk && !$this->eof())) {
            throw new ObjectStorageBackendException();
        }

        return $chunk;
    }

    public function eof(): bool
    {
        $this->check(true);
        $eof = feof($this->stream);
        $this->check(true);

        return $eof;
    }

    public function writeChunk(string $chunk): void
    {
        $this->check(false);
        if (strlen($chunk) > self::CHUNK_SIZE) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        while ('' !== $chunk) {
            $written = @fwrite($this->stream, $chunk);
            $this->check(false);
            if (false === $written || 0 === $written) {
                throw new ObjectStorageBackendException();
            }
            $chunk = substr($chunk, $written);
        }
    }

    /** @phpstan-assert resource $this->stream */
    private function check(bool $reading): void
    {
        if (!$this->active || $reading !== $this->reading || !is_resource($this->stream)) {
            throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
        }
        try {
            ($this->guard)();
        } catch (\Throwable) {
            // The backend may already have consumed earlier chunks.
            throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
        }
    }
}
