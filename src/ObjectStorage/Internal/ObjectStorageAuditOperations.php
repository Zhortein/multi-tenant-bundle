<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Internal;

use Zhortein\MultiTenantBundle\ObjectStorage\AuditListingBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\LogicalObjectIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectAuditPage;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservationState;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageAuditScope;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationInventoryPage;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;

/** @internal Implementation of the optional capability on the existing facade and lifecycle. */
trait ObjectStorageAuditOperations
{
    private function codec(): ObjectStorageAuditCodec
    {
        return $this->auditCodec ?? throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
    }

    public function inventoryLocations(int $limit = 100, ?string $cursor = null): StorageLocationInventoryPage
    {
        $tenant = $this->tenant();
        $epoch = $this->epoch;
        $namespace = $this->namespaceResolver->resolve($tenant);
        Validation::opaque($namespace);
        $this->guard($tenant, $epoch);
        // Bind facade pagination to the namespace as well as the registry's tenant-ID boundary.
        $inner = null;
        if (null !== $cursor) {
            $data = $this->codec()->open($cursor, 'tenant-locations');
            if (['namespace', 'cursor'] !== array_keys($data) || $namespace !== $data['namespace'] || !is_string($data['cursor'])) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            $inner = $data['cursor'];
        }
        $this->codec();
        $page = $this->registry->inventory($tenant, $limit, $inner);
        $this->guard($tenant, $epoch);
        if ($namespace !== $this->namespaceResolver->resolve($tenant)) {
            throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
        }
        $this->guard($tenant, $epoch);

        return new StorageLocationInventoryPage($page->locations, null === $page->nextCursor ? null : $this->codec()->seal(['namespace' => $namespace, 'cursor' => $page->nextCursor], 'tenant-locations'), $page->registryRevision);
    }

    public function auditScope(string $locationId): StorageAuditScope
    {
        $tenant = $this->tenant();
        $epoch = $this->epoch;
        $this->codec();
        $namespace = $this->namespaceResolver->resolve($tenant);
        Validation::opaque($namespace);
        $this->guard($tenant, $epoch);
        $location = $this->registry->forTenantLocation($locationId, $tenant);
        $binding = $location->fingerprint();
        $this->guard($tenant, $epoch);
        if ($namespace !== $this->namespaceResolver->resolve($tenant)) {
            throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
        }
        $this->guard($tenant, $epoch);

        return new StorageAuditScope($locationId, $binding, $namespace, $this->registry->revision);
    }

    public function writeWithIdentity(StoredObjectReference $reference, string $content, LogicalObjectIdentity $identity): void
    {
        [$location, $key, $guard] = $this->validate($reference);
        $envelope = $this->identityEnvelope($reference, $identity);
        $backend = $location->backend;
        if (!$backend instanceof ObjectIdentityBackendInterface) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $this->invoke($guard, static fn () => $backend->writeWithIdentity($key, $content, $envelope));
    }

    public function writeFromStreamWithIdentity(StoredObjectReference $reference, mixed $stream, LogicalObjectIdentity $identity): void
    {
        [$location, $key, $guard] = $this->validate($reference);
        $envelope = $this->identityEnvelope($reference, $identity);
        $backend = $location->backend;
        if (!$backend instanceof ObjectIdentityBackendInterface) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $scoped = new ScopedStream($stream, $guard, true);
        try {
            $this->invoke($guard, static fn () => $backend->writeFromStreamWithIdentity($key, $scoped, $envelope));
        } finally {
            $scoped->invalidate();
        }
    }

    private function identityEnvelope(StoredObjectReference $reference, LogicalObjectIdentity $identity): string
    {
        if (!$identity->belongsToNamespace($reference->tenantNamespace)) {
            throw new ObjectStorageException(ObjectStorageError::FOREIGN_REFERENCE);
        }

        return $this->codec()->seal($identity->toArray(), 'identity');
    }

    public function observe(StoredObjectReference $reference): ObjectObservation
    {
        [$location, $key, $guard] = $this->validate($reference);
        $codec = $this->codec();
        $id = hash('sha256', $reference->toJson());
        $backend = $location->backend;
        if (!$backend instanceof ObjectIdentityBackendInterface) {
            return new ObjectObservation($id, ObjectObservationState::UNAVAILABLE, $reference);
        }
        try {
            $result = $this->invoke($guard, static fn () => $backend->observeIdentity($key));
        } catch (ObjectStorageException $exception) {
            // Never downgrade a changed tenant/binding/reset into an individual anomaly.
            $guard();

            return new ObjectObservation($id, ObjectStorageError::OBJECT_NOT_FOUND === $exception->reason ? ObjectObservationState::INDETERMINATE : ObjectObservationState::UNREACHABLE, $reference);
        }
        $envelope = $result->envelope;
        if (null === $envelope) {
            return new ObjectObservation($id, ObjectObservationState::IDENTITY_ABSENT, $reference, metadata: $result->metadata);
        }
        try {
            if (strlen($envelope) > 2048) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
            }
            $identity = LogicalObjectIdentity::fromArray($codec->open($envelope, 'identity'));
        } catch (ObjectStorageException) {
            return new ObjectObservation($id, ObjectObservationState::IDENTITY_INVALID, $reference, metadata: $result->metadata);
        }
        if (!$identity->belongsToNamespace($reference->tenantNamespace)) {
            return new ObjectObservation(bin2hex(random_bytes(32)), ObjectObservationState::FOREIGN);
        }

        return new ObjectObservation($id, ObjectObservationState::VERIFIED, $reference, $identity, $result->metadata);
    }

    public function auditList(StorageAuditScope $scope, int $limit = 100, ?string $cursor = null): ObjectAuditPage
    {
        // A fixed in-memory validation address is never allocated, persisted or sent to a backend.
        $validation = new StoredObjectReference($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, str_repeat('0', 64));
        [$location, , $guard] = $this->validate($validation);
        $codec = $this->codec();
        if ($scope->registryRevision !== $this->registry->revision) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
        if ($limit < 1 || $limit > 1000) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $backend = $location->backend;
        if (!$backend instanceof AuditListingBackendInterface) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $context = [$validation->toArray(), $scope->registryRevision, $limit];
        $prefix = $this->prefix($validation);
        $after = null;
        if (null !== $cursor) {
            $data = $codec->open($cursor, 'objects');
            if (['context', 'after'] !== array_keys($data) || $context !== $data['context'] || !is_string($data['after'])) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            $after = $data['after'];
            if (!str_starts_with($after, $prefix) || strlen($after) > 1024) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
        }
        $page = $this->invoke($guard, static fn () => $backend->auditList($prefix, $limit, $after));
        if (!array_is_list($page->keys) || count($page->keys) > $limit || ($page->hasMore && [] === $page->keys)) {
            throw new ObjectStorageBackendException();
        }
        // Validate the entire page before issuing any per-entry I/O.
        $previous = $after;
        foreach ($page->keys as $key) {
            if (strlen($key) > 1024 || 1 !== preg_match('//u', $key) || !str_starts_with($key, $prefix) || (null !== $previous && strcmp($key, $previous) <= 0)) {
                throw new ObjectStorageBackendException();
            }
            $previous = $key;
        }
        $observations = [];
        foreach ($page->keys as $key) {
            $guard();
            try {
                $reference = new StoredObjectReference($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, substr($key, strlen($prefix)));
            } catch (ObjectStorageException) {
                $observations[] = new ObjectObservation(bin2hex(random_bytes(32)), ObjectObservationState::INVALID_REFERENCE);
                continue;
            }
            $observations[] = $this->observe($reference);
        }
        $guard();

        return new ObjectAuditPage($observations, $page->hasMore ? $codec->seal(['context' => $context, 'after' => $previous], 'objects') : null);
    }
}
