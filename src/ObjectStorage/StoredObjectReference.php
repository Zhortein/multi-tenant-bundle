<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

/** A durable, non-authorizing address. Persist toJson() or toArray(), never a backend. */
final readonly class StoredObjectReference implements \JsonSerializable, \Stringable
{
    public function __construct(
        public string $locationId,
        public string $locationBinding,
        public string $tenantNamespace,
        public string $key,
        public int $formatVersion = 1,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if (1 !== $this->formatVersion) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
        Validation::identifier($this->locationId);
        Validation::opaque($this->locationBinding);
        Validation::opaque($this->tenantNamespace);
        Validation::opaque($this->key);
    }

    /** @return array{formatVersion: int, locationId: string, locationBinding: string, tenantNamespace: string, key: string} */
    public function toArray(): array
    {
        $this->validate();

        return ['formatVersion' => $this->formatVersion, 'locationId' => $this->locationId,
            'locationBinding' => $this->locationBinding, 'tenantNamespace' => $this->tenantNamespace, 'key' => $this->key];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        if (['formatVersion', 'key', 'locationBinding', 'locationId', 'tenantNamespace'] !== $keys
            || !is_int($data['formatVersion']) || !is_string($data['locationId'])
            || !is_string($data['locationBinding']) || !is_string($data['tenantNamespace']) || !is_string($data['key'])) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }

        return new self($data['locationId'], $data['locationBinding'], $data['tenantNamespace'], $data['key'], $data['formatVersion']);
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 1024) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
        if (!is_array($data)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }

        return self::fromArray($data);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{formatVersion: int, locationId: string, locationBinding: string, tenantNamespace: string, key: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array{formatVersion: int, locationId: string, locationBinding: string, tenantNamespace: string, key: string} */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        $reference = self::fromArray($data);
        $this->formatVersion = $reference->formatVersion;
        $this->locationId = $reference->locationId;
        $this->locationBinding = $reference->locationBinding;
        $this->tenantNamespace = $reference->tenantNamespace;
        $this->key = $reference->key;
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}
