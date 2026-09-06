<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/**
 * Backend-local primitives. All keys are fully qualified and validated by the facade.
 * Implementations never select tenants/providers. Objects are private by default.
 * See docs/object-storage.md for the normative adapter contract and error outcomes.
 */
interface ObjectStorageBackendInterface
{
    public function write(string $qualifiedKey, string $content): void;

    public function writeFromStream(string $qualifiedKey, ObjectStreamSourceInterface $source): void;

    public function read(string $qualifiedKey): string;

    /** Must consume synchronously and close all backend resources before returning or throwing. */
    public function readToStream(string $qualifiedKey, ObjectStreamDestinationInterface $destination): void;

    /** Only absence is false; unavailability must throw. */
    public function exists(string $qualifiedKey): bool;

    public function metadata(string $qualifiedKey): ObjectMetadata;

    /** Exact prefix including trailing slash; exclusive keyset cursor under that prefix. */
    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage;

    /** Same-location only. Normal return means complete; no universal atomicity is promised. */
    public function copy(string $sourceKey, string $destinationKey): void;

    /** Do not delete the source after an unknown copy result. */
    public function move(string $sourceKey, string $destinationKey): void;

    /** Missing object is successful; never recursive. */
    public function delete(string $qualifiedKey): void;
}
