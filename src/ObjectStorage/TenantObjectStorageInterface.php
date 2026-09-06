<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Symfony\Contracts\Service\ResetInterface;

interface TenantObjectStorageInterface extends ResetInterface
{
    /** Allocate a reference for a new object without backend I/O. */
    public function allocate(): StoredObjectReference;

    public function write(StoredObjectReference $reference, string $content): void;

    /** @param resource $stream Read from current position; caller retains ownership. */
    public function writeFromStream(StoredObjectReference $reference, mixed $stream): void;

    public function read(StoredObjectReference $reference): string;

    /** @param resource $stream Synchronous copy to caller-owned destination, at its current position. */
    public function readToStream(StoredObjectReference $reference, mixed $stream): void;

    public function exists(StoredObjectReference $reference): bool;

    public function metadata(StoredObjectReference $reference): ObjectMetadata;

    /** Scope can be an allocated reference; its object need not exist. Maximum limit: 1000. */
    public function list(StoredObjectReference $scope, int $limit = 100, ?string $cursor = null): ObjectListingPage;

    public function copy(StoredObjectReference $source, StoredObjectReference $destination): void;

    public function move(StoredObjectReference $source, StoredObjectReference $destination): void;

    public function delete(StoredObjectReference $reference): void;

    public function temporaryUrl(StoredObjectReference $reference, ?int $ttl = null): TemporaryObjectUrl;
}
