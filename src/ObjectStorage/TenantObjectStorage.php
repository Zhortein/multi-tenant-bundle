<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\ScopedStream;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;

final class TenantObjectStorage implements TenantObjectStorageInterface, EventSubscriberInterface
{
    public const MAX_LIST_LIMIT = 1000;

    // Only an invalidation token is retained, never tenant selections, streams or pages.
    private object $epoch;

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantStorageProviderSelectorInterface $selector,
        private readonly TenantStorageNamespaceResolverInterface $namespaceResolver,
        private readonly ObjectStorageRegistry $registry,
        private readonly bool $temporaryUrlsEnabled = false,
        private readonly int $defaultTtl = 300,
        private readonly int $maxTtl = 900,
    ) {
        if ($defaultTtl <= 0 || $maxTtl < $defaultTtl || $maxTtl > 86400) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $this->reset();
    }

    public static function getSubscribedEvents(): array
    {
        return [TenantContextEndedEvent::class => ['reset', 2048], TenantContextStartedEvent::class => ['reset', 2048]];
    }

    public function reset(): void
    {
        $this->epoch = new \stdClass();
    }

    public function allocate(): StoredObjectReference
    {
        $tenant = $this->tenant();
        $epoch = $this->epoch;
        $location = $this->registry->forProvider($this->selector->selectForNewObject($tenant));
        $reference = new StoredObjectReference($location->id, $location->fingerprint(), $this->namespaceResolver->resolve($tenant), bin2hex(random_bytes(32)));
        $this->guard($tenant, $epoch);
        $this->validate($reference);

        return $reference;
    }

    public function write(StoredObjectReference $reference, string $content): void
    {
        [$location, $key, $guard] = $this->validate($reference);
        $this->invoke($guard, static fn () => $location->backend->write($key, $content));
    }

    public function writeFromStream(StoredObjectReference $reference, mixed $stream): void
    {
        $this->stream($reference, $stream, true);
    }

    public function read(StoredObjectReference $reference): string
    {
        [$location, $key, $guard] = $this->validate($reference);

        return $this->invoke($guard, static fn () => $location->backend->read($key));
    }

    public function readToStream(StoredObjectReference $reference, mixed $stream): void
    {
        $this->stream($reference, $stream, false);
    }

    public function exists(StoredObjectReference $reference): bool
    {
        [$location, $key, $guard] = $this->validate($reference);

        return $this->invoke($guard, static fn () => $location->backend->exists($key));
    }

    public function metadata(StoredObjectReference $reference): ObjectMetadata
    {
        [$location, $key, $guard] = $this->validate($reference);

        return $this->invoke($guard, static fn () => $location->backend->metadata($key));
    }

    public function list(StoredObjectReference $scope, int $limit = 100, ?string $cursor = null): ObjectListingPage
    {
        [$location, , $guard] = $this->validate($scope);
        if ($limit < 1 || $limit > self::MAX_LIST_LIMIT) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $prefix = $this->prefix($scope);
        $afterKey = null;
        if (null !== $cursor) {
            if (strlen($cursor) > 1400 || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor)) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if (false === $decoded) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            $after = StoredObjectReference::fromJson($decoded);
            $this->validate($after);
            if ($after->locationId !== $scope->locationId || $this->cursor($after) !== $cursor) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            $afterKey = $prefix.$after->key;
        }
        $page = $this->invoke($guard, static fn () => $location->backend->list($prefix, $limit, $afterKey));
        if (!array_is_list($page->keys) || count($page->keys) > $limit || ($page->hasMore && [] === $page->keys)) {
            throw new ObjectStorageBackendException();
        }
        $references = [];
        $previous = $afterKey;
        foreach ($page->keys as $key) {
            if (!str_starts_with($key, $prefix) || (null !== $previous && strcmp($key, $previous) <= 0)) {
                throw new ObjectStorageBackendException();
            }
            try {
                $reference = new StoredObjectReference($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, substr($key, strlen($prefix)));
                $this->validate($reference);
            } catch (ObjectStorageException) {
                throw new ObjectStorageBackendException();
            }
            $references[] = $reference;
            $previous = $key;
        }
        $guard();
        $last = [] === $references ? null : $references[array_key_last($references)];

        return new ObjectListingPage($references, $page->hasMore && null !== $last ? $this->cursor($last) : null);
    }

    public function copy(StoredObjectReference $source, StoredObjectReference $destination): void
    {
        $this->transfer($source, $destination, false);
    }

    public function move(StoredObjectReference $source, StoredObjectReference $destination): void
    {
        $this->transfer($source, $destination, true);
    }

    public function delete(StoredObjectReference $reference): void
    {
        [$location, $key, $guard] = $this->validate($reference);
        $this->invoke($guard, static fn () => $location->backend->delete($key));
    }

    public function temporaryUrl(StoredObjectReference $reference, ?int $ttl = null): TemporaryObjectUrl
    {
        [$location, $key, $guard] = $this->validate($reference);
        $ttl ??= $this->defaultTtl;
        if ($ttl <= 0 || $ttl > $this->maxTtl) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $backend = $location->backend;
        if (!$this->temporaryUrlsEnabled || !$location->temporaryUrls || !$backend instanceof TemporaryObjectUrlBackendInterface) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $url = $this->invoke($guard, static fn () => $backend->temporaryUrl($key, $expiresAt));
        if ($url->expiresAt > $expiresAt || $url->expiresAt <= new \DateTimeImmutable()) {
            throw new ObjectStorageBackendException();
        }

        return $url;
    }

    /** @return array{StorageLocation, string, \Closure(): void} */
    private function validate(StoredObjectReference $reference): array
    {
        $tenant = $this->tenant();
        $epoch = $this->epoch;
        $reference->validate();
        $namespace = $this->namespaceResolver->resolve($tenant);
        Validation::opaque($namespace);
        if (!hash_equals($namespace, $reference->tenantNamespace)) {
            throw new ObjectStorageException(ObjectStorageError::FOREIGN_REFERENCE);
        }
        $location = $this->registry->location($reference->locationId);
        if (!hash_equals($location->fingerprint(), $reference->locationBinding)) {
            throw new ObjectStorageException(ObjectStorageError::BINDING_MISMATCH);
        }
        if (!$location->allows($tenant)) {
            throw new ObjectStorageException(ObjectStorageError::TENANT_NOT_ALLOWED);
        }
        $guard = function () use ($tenant, $epoch, $reference, $location): void {
            $this->guard($tenant, $epoch);
            if (!hash_equals($reference->tenantNamespace, $this->namespaceResolver->resolve($tenant))
                || !hash_equals($reference->locationBinding, $location->fingerprint()) || !$location->allows($tenant)) {
                throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
            }
            $this->guard($tenant, $epoch);
        };
        $guard();

        return [$location, $this->prefix($reference).$reference->key, $guard];
    }

    private function tenant(): TenantInterface
    {
        $tenant = $this->tenantContext->getTenant() ?? throw new ObjectStorageException(ObjectStorageError::MISSING_CONTEXT);
        Validation::tenantId($tenant->getId());

        return $tenant;
    }

    private function guard(TenantInterface $tenant, object $epoch): void
    {
        if ($epoch !== $this->epoch || $tenant !== $this->tenantContext->getTenant()) {
            throw new ObjectStorageException(ObjectStorageError::CONTEXT_CHANGED);
        }
    }

    private function prefix(StoredObjectReference $reference): string
    {
        return 'objects/v1/'.$reference->tenantNamespace.'/';
    }

    private function cursor(StoredObjectReference $reference): string
    {
        return rtrim(strtr(base64_encode($reference->toJson()), '+/', '-_'), '=');
    }

    /** @param resource $stream */
    private function stream(StoredObjectReference $reference, mixed $stream, bool $reading): void
    {
        [$location, $key, $guard] = $this->validate($reference);
        $scoped = new ScopedStream($stream, $guard, $reading);
        try {
            $this->invoke($guard, static function () use ($location, $key, $scoped, $reading): void {
                if ($reading) {
                    $location->backend->writeFromStream($key, $scoped);
                } else {
                    $location->backend->readToStream($key, $scoped);
                }
            });
        } finally {
            $scoped->invalidate();
        }
    }

    private function transfer(StoredObjectReference $source, StoredObjectReference $destination, bool $move): void
    {
        [$location, $sourceKey, $sourceGuard] = $this->validate($source);
        [, $destinationKey, $destinationGuard] = $this->validate($destination);
        if ($source->locationId !== $destination->locationId || $sourceKey === $destinationKey) {
            throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        }
        $guard = static function () use ($sourceGuard, $destinationGuard): void {
            $sourceGuard();
            $destinationGuard();
        };
        $this->invoke($guard, static function () use ($location, $sourceKey, $destinationKey, $move): void {
            if ($move) {
                $location->backend->move($sourceKey, $destinationKey);
            } else {
                $location->backend->copy($sourceKey, $destinationKey);
            }
        });
    }

    /** @template T
     * @param \Closure(): void $guard
     * @param \Closure(): T    $operation
     *
     * @return T
     */
    private function invoke(\Closure $guard, \Closure $operation): mixed
    {
        $guard();
        try {
            $result = $operation();
        } catch (ObjectStorageBackendException $exception) {
            throw $exception;
        } catch (ObjectStorageException $exception) {
            if (ObjectStorageError::OBJECT_NOT_FOUND === $exception->reason || ObjectStorageError::UNSUPPORTED_OPERATION === $exception->reason) {
                throw $exception;
            }
            // A stream/context failure after entry may already have applied bytes.
            throw new ObjectStorageBackendException();
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
        try {
            $guard();
        } catch (\Throwable) {
            // A post-operation check cannot establish that completed I/O was not applied.
            throw new ObjectStorageBackendException();
        }

        return $result;
    }
}
