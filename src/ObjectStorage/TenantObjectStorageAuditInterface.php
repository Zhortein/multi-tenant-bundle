<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Optional additive capability. Existing TenantObjectStorageInterface implementers remain unchanged. */
interface TenantObjectStorageAuditInterface
{
    public function inventoryLocations(int $limit = 100, ?string $cursor = null): StorageLocationInventoryPage;

    public function auditScope(string $locationId): StorageAuditScope;

    public function writeWithIdentity(StoredObjectReference $reference, string $content, LogicalObjectIdentity $identity): void;

    /** @param resource $stream */
    public function writeFromStreamWithIdentity(StoredObjectReference $reference, mixed $stream, LogicalObjectIdentity $identity): void;

    public function observe(StoredObjectReference $reference): ObjectObservation;

    public function auditList(StorageAuditScope $scope, int $limit = 100, ?string $cursor = null): ObjectAuditPage;
}
