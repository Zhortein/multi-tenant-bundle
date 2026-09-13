<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

/** A correlation claim, not an address, authorization or content attestation. */
final readonly class LogicalObjectIdentity implements \JsonSerializable
{
    public function __construct(
        public string $correlationId,
        public string $referenceFingerprint,
        public string $generation,
        public string $tenantFingerprint,
        public int $version = 1,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if (1 !== $this->version) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        Validation::opaque($this->correlationId);
        Validation::opaque($this->referenceFingerprint);
        Validation::opaque($this->tenantFingerprint);
        Validation::identifier($this->generation);
    }

    public static function forReference(StoredObjectReference $reference, string $correlationId): self
    {
        $reference->validate();

        return new self($correlationId, hash('sha256', $reference->toJson()), $reference->locationId, hash('sha256', $reference->tenantNamespace));
    }

    public function matchesReference(StoredObjectReference $reference): bool
    {
        $this->validate();

        return hash_equals($this->referenceFingerprint, hash('sha256', $reference->toJson()))
            && $this->generation === $reference->locationId && $this->belongsToNamespace($reference->tenantNamespace);
    }

    public function sameLogicalObject(self $other): bool
    {
        $this->validate();
        $other->validate();

        return hash_equals($this->tenantFingerprint, $other->tenantFingerprint) && hash_equals($this->correlationId, $other->correlationId);
    }

    public function belongsToNamespace(string $namespace): bool
    {
        $this->validate();

        return hash_equals($this->tenantFingerprint, hash('sha256', $namespace));
    }

    /** @return array{version: int, correlationId: string, referenceFingerprint: string, generation: string, tenantFingerprint: string} */
    public function toArray(): array
    {
        $this->validate();

        return ['version' => $this->version, 'correlationId' => $this->correlationId, 'referenceFingerprint' => $this->referenceFingerprint,
            'generation' => $this->generation, 'tenantFingerprint' => $this->tenantFingerprint];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        if (['correlationId', 'generation', 'referenceFingerprint', 'tenantFingerprint', 'version'] !== $keys
            || !is_int($data['version']) || !is_string($data['correlationId']) || !is_string($data['referenceFingerprint'])
            || !is_string($data['generation']) || !is_string($data['tenantFingerprint'])) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }

        return new self($data['correlationId'], $data['referenceFingerprint'], $data['generation'], $data['tenantFingerprint'], $data['version']);
    }

    /** @return array{version: int, correlationId: string, referenceFingerprint: string, generation: string, tenantFingerprint: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array{version: int, correlationId: string, referenceFingerprint: string, generation: string, tenantFingerprint: string} */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        $identity = self::fromArray($data);
        $this->version = $identity->version;
        $this->correlationId = $identity->correlationId;
        $this->referenceFingerprint = $identity->referenceFingerprint;
        $this->generation = $identity->generation;
        $this->tenantFingerprint = $identity->tenantFingerprint;
    }
}
