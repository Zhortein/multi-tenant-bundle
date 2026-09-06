<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationBindingInterface;

class InstrumentedBackend implements ObjectStorageBackendInterface, StorageLocationBindingInterface
{
    public array $calls = [];
    public array $objects = [];
    public ?\Throwable $failure = null;
    public ?\Closure $duringIo = null;
    public ?BackendObjectPage $page = null;
    public ObjectStreamSourceInterface|ObjectStreamDestinationInterface|null $retainedStream = null;
    public string $credentials = 'synthetic-first';
    public array $options = [];
    public bool $bindingFailure = false;

    public function __construct(public string $target = 'target-one', public string $root = 'root-one')
    {
    }

    public function identity(ObjectStorageBackendInterface $backend): PhysicalStorageIdentity
    {
        if ($this->bindingFailure) {
            throw new \RuntimeException('Synthetic binding failure');
        }
        if ($backend !== $this) {
            throw new \LogicException('Binding must describe the selected backend.');
        }

        return new PhysicalStorageIdentity('synthetic', $this->target, $this->root, $this->options);
    }

    protected function record(string $operation, string ...$keys): void
    {
        $this->calls[] = [$operation, ...$keys];
        if (null !== $this->duringIo) {
            ($this->duringIo)();
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    private function content(string $key): string
    {
        return $this->objects[$key] ?? throw new ObjectStorageException(ObjectStorageError::OBJECT_NOT_FOUND);
    }

    public function write(string $qualifiedKey, string $content): void
    {
        $this->record('write', $qualifiedKey);
        $this->objects[$qualifiedKey] = $content;
    }

    public function writeFromStream(string $qualifiedKey, ObjectStreamSourceInterface $source): void
    {
        $this->retainedStream = $source;
        $this->record('writeFromStream', $qualifiedKey);
        $this->objects[$qualifiedKey] = '';
        while (!$source->eof()) {
            $this->objects[$qualifiedKey] .= $source->readChunk();
        }
    }

    public function read(string $qualifiedKey): string
    {
        $this->record('read', $qualifiedKey);

        return $this->content($qualifiedKey);
    }

    public function readToStream(string $qualifiedKey, ObjectStreamDestinationInterface $destination): void
    {
        $this->retainedStream = $destination;
        $this->record('readToStream', $qualifiedKey);
        foreach (str_split($this->content($qualifiedKey), 65536) as $chunk) {
            $destination->writeChunk($chunk);
        }
    }

    public function exists(string $qualifiedKey): bool
    {
        $this->record('exists', $qualifiedKey);

        return isset($this->objects[$qualifiedKey]);
    }

    public function metadata(string $qualifiedKey): ObjectMetadata
    {
        $this->record('metadata', $qualifiedKey);

        return new ObjectMetadata(strlen($this->content($qualifiedKey)), new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        $this->record('list', $tenantPrefix, (string) $limit, $afterKey ?? '');
        if (null !== $this->page) {
            return $this->page;
        }
        $keys = array_keys($this->objects);
        sort($keys, SORT_STRING);
        $keys = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, $tenantPrefix) && (null === $afterKey || strcmp($key, $afterKey) > 0)));

        return new BackendObjectPage(array_slice($keys, 0, $limit), count($keys) > $limit);
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        $this->record('copy', $sourceKey, $destinationKey);
        $this->objects[$destinationKey] = $this->content($sourceKey);
    }

    public function move(string $sourceKey, string $destinationKey): void
    {
        $this->record('move', $sourceKey, $destinationKey);
        $this->objects[$destinationKey] = $this->content($sourceKey);
        unset($this->objects[$sourceKey]);
    }

    public function delete(string $qualifiedKey): void
    {
        $this->record('delete', $qualifiedKey);
        unset($this->objects[$qualifiedKey]);
    }
}
