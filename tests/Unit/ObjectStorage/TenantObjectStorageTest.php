<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\OperationOutcome;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantStorageProviderSelectorInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InstrumentedBackend;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\SigningBackend;

final class TenantObjectStorageTest extends TestCase
{
    private TenantContext $context;
    private TenantObjectStorage $storage;
    private SigningBackend $shared;
    private InstrumentedBackend $dedicated;
    private ObjectStorageRegistry $registry;
    private ConfiguredTenantStorageNamespaceResolver $namespaces;
    private TestTenant $a;
    private TestTenant $b;

    protected function setUp(): void
    {
        $this->a = (new TestTenant())->setId(1)->setSlug('same-business-slug');
        $this->b = (new TestTenant())->setId(2)->setSlug('same-business-slug');
        $this->namespaces = new ConfiguredTenantStorageNamespaceResolver(['1' => str_repeat('a', 64), '2' => str_repeat('b', 64)]);
        $dispatcher = new EventDispatcher();
        $this->context = new TenantContext($dispatcher);
        $this->shared = new SigningBackend();
        $this->dedicated = new InstrumentedBackend('target-two');
        $this->registry = new ObjectStorageRegistry([
            new StorageLocation('shared_v1', $this->shared, $this->shared, ['*'], true),
            new StorageLocation('dedicated_v1', $this->dedicated, $this->dedicated, ['1']),
        ], ['shared' => 'shared_v1', 'dedicated' => 'dedicated_v1']);
        $this->storage = $this->facade(new ConfiguredTenantStorageProviderSelector('shared'));
        $dispatcher->addSubscriber($this->storage);
        $this->context->setTenant($this->a);
    }

    private function facade(TenantStorageProviderSelectorInterface $selector, ?ObjectStorageRegistry $registry = null): TenantObjectStorage
    {
        return new TenantObjectStorage($this->context, $selector, $this->namespaces, $registry ?? $this->registry, true);
    }

    private function assertError(ObjectStorageError $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Operation must fail closed.');
        } catch (ObjectStorageException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    public function testAllocationIsOpaqueServerGeneratedAndDoesNotPerformIo(): void
    {
        $one = $this->storage->allocate();
        $two = $this->storage->allocate();
        self::assertSame('shared_v1', $one->locationId);
        self::assertSame(str_repeat('a', 64), $one->tenantNamespace);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $one->key);
        self::assertNotSame($one->key, $two->key);
        foreach (['same-business-slug', 'original.pdf', 'email', '@', 'title', 'endpoint', 'bucket', 'credentials'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $one->toJson());
        }
        self::assertSame([], $this->shared->calls);
        self::assertSame([], $this->dedicated->calls);
    }

    public function testOverrideForAAndDefaultForB(): void
    {
        $storage = $this->facade(new ConfiguredTenantStorageProviderSelector('shared', ['1' => 'dedicated']));
        self::assertSame('dedicated_v1', $storage->allocate()->locationId);
        $this->context->setTenant($this->b);
        self::assertSame('shared_v1', $storage->allocate()->locationId);
        self::assertSame([], $this->shared->calls);
    }

    public function testSlugChangesAndIdenticalSlugsDoNotChangeOwnership(): void
    {
        $a = $this->storage->allocate();
        $this->storage->write($a, 'A');
        $this->a->setSlug('renamed');
        self::assertSame('A', $this->storage->read($a));
        $this->context->setTenant($this->b);
        $b = $this->storage->allocate();
        $this->storage->write($b, 'B');
        self::assertNotSame($a->tenantNamespace, $b->tenantNamespace);
        $this->assertError(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->storage->read($a));
        $this->context->setTenant($this->a);
        self::assertSame('A', $this->storage->read($a));
        $this->context->clear();
        $this->assertError(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->allocate());
        $this->assertError(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->read($a));
    }

    public function testContentMetadataCopyMoveAndIdempotentDeletion(): void
    {
        $source = $this->storage->allocate();
        $copy = $this->storage->allocate();
        $moved = $this->storage->allocate();
        self::assertFalse($this->storage->exists($source));
        $this->storage->write($source, "bytes\0");
        self::assertSame(6, $this->storage->metadata($source)->size);
        $this->storage->copy($source, $copy);
        $this->storage->move($copy, $moved);
        self::assertSame("bytes\0", $this->storage->read($source));
        self::assertSame("bytes\0", $this->storage->read($moved));
        self::assertFalse($this->storage->exists($copy));
        $this->storage->delete($source);
        $this->storage->delete($source);
        self::assertFalse($this->storage->exists($source));
        foreach (['read', 'metadata'] as $operation) {
            $this->assertError(ObjectStorageError::OBJECT_NOT_FOUND, fn () => $this->storage->$operation($source));
        }
    }

    public static function primitives(): iterable
    {
        foreach (['write', 'writeFromStream', 'read', 'readToStream', 'exists', 'metadata', 'list', 'copy', 'copyDestination', 'move', 'moveDestination', 'delete', 'temporaryUrl'] as $method) {
            yield $method => [$method];
        }
    }

    private function invokePrimitive(string $method, StoredObjectReference $reference): void
    {
        $stream = fopen('php://temp', 'w+');
        try {
            match ($method) {
                'write' => $this->storage->write($reference, 'payload'),
                'writeFromStream', 'readToStream' => $this->storage->$method($reference, $stream),
                'copy', 'move' => $this->storage->$method($reference, $this->storage->allocate()),
                'copyDestination' => $this->storage->copy($this->storage->allocate(), $reference),
                'moveDestination' => $this->storage->move($this->storage->allocate(), $reference),
                default => $this->storage->$method($reference),
            };
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('primitives')]
    public function testForeignReferenceRefusedBeforeEveryPrimitive(string $method): void
    {
        $reference = $this->storage->allocate();
        $this->context->setTenant($this->b);
        $this->assertError(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->invokePrimitive($method, $reference));
        self::assertSame([], $this->shared->calls);
        self::assertSame([], $this->dedicated->calls);
    }

    #[DataProvider('primitives')]
    public function testMissingContextRefusedBeforeEveryPrimitive(string $method): void
    {
        $reference = $this->storage->allocate();
        $this->context->reset();
        $this->assertError(ObjectStorageError::MISSING_CONTEXT, fn () => $this->invokePrimitive($method, $reference));
        self::assertSame([], $this->shared->calls);
    }

    #[DataProvider('primitives')]
    public function testChangedPhysicalTargetRefusedBeforeEveryPrimitive(string $method): void
    {
        $reference = $this->storage->allocate();
        $this->shared->target = 'other-effective-target';
        $this->assertError(ObjectStorageError::BINDING_MISMATCH, fn () => $this->invokePrimitive($method, $reference));
        self::assertSame([], $this->shared->calls);
    }

    public function testNoFallbackForUnknownProviderOrLocationOrDisallowedTenant(): void
    {
        $selector = new class implements TenantStorageProviderSelectorInterface {
            public function selectForNewObject(TenantInterface $tenant): string
            {
                return 'unknown';
            }
        };
        $this->assertError(ObjectStorageError::UNKNOWN_PROVIDER, fn () => $this->facade($selector)->allocate());
        $ref = $this->storage->allocate();
        $unknown = new StoredObjectReference('unknown_v1', $ref->locationBinding, $ref->tenantNamespace, $ref->key);
        $this->assertError(ObjectStorageError::UNKNOWN_LOCATION, fn () => $this->storage->exists($unknown));
        $this->context->setTenant($this->b);
        $this->assertError(ObjectStorageError::TENANT_NOT_ALLOWED, fn () => $this->facade(new ConfiguredTenantStorageProviderSelector('dedicated'))->allocate());
        self::assertSame([], $this->shared->calls);
        self::assertSame([], $this->dedicated->calls);
    }

    public function testProviderChangePreservesOldPhysicalReferenceAndCredentialsDoNotChangeBinding(): void
    {
        $old = $this->storage->allocate();
        $this->storage->write($old, 'historical');
        $this->shared->credentials = 'synthetic-renewed';
        $newRegistry = new ObjectStorageRegistry([
            new StorageLocation('shared_v1', $this->shared, $this->shared, ['*']),
            new StorageLocation('shared_v2', $this->dedicated, $this->dedicated, ['*']),
        ], ['shared' => 'shared_v2']);
        $storage = $this->facade(new ConfiguredTenantStorageProviderSelector('shared'), $newRegistry);
        self::assertSame('shared_v2', $storage->allocate()->locationId);
        self::assertSame('historical', $storage->read($old));
        self::assertSame([], $this->dedicated->calls);
        $this->shared->root = 'new-root';
        $this->assertError(ObjectStorageError::BINDING_MISMATCH, fn () => $storage->read($old));
    }

    public function testCopyAndMoveRejectDifferentLocationsAndSelfTransfersBeforeIo(): void
    {
        $source = $this->storage->allocate();
        $destination = $this->facade(new ConfiguredTenantStorageProviderSelector('dedicated'))->allocate();
        foreach (['copy', 'move'] as $method) {
            $this->assertError(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->$method($source, $destination));
            $this->assertError(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->$method($source, $source));
        }
        self::assertSame([], $this->shared->calls);
        self::assertSame([], $this->dedicated->calls);
    }

    public function testListingIsBoundedOrderedTenantScopedAndCursorValidated(): void
    {
        $refs = [];
        for ($i = 0; $i < 5; ++$i) {
            $ref = $this->storage->allocate();
            $refs[] = $ref;
            $this->storage->write($ref, (string) $i);
        }
        $prefix = 'objects/v1/'.$refs[0]->tenantNamespace.'/';
        $this->shared->objects['objects/v1/'.$refs[0]->tenantNamespace.'0/'.str_repeat('c', 64)] = 'neighbor';
        $this->shared->objects['objects/v1/'.str_repeat('b', 64).'/'.str_repeat('c', 64)] = 'foreign';
        $found = [];
        $cursor = null;
        do {
            $page = $this->storage->list($refs[0], 2, $cursor);
            self::assertLessThanOrEqual(2, count($page->references));
            $found = [...$found, ...$page->references];
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        self::assertCount(5, $found);
        foreach ($found as $ref) {
            self::assertSame($refs[0]->tenantNamespace, $ref->tenantNamespace);
        }
        self::assertSame($prefix, $this->shared->calls[array_key_last($this->shared->calls)][1]);
        $calls = $this->shared->calls;
        foreach ([0, -1, 1001] as $limit) {
            $this->assertError(ObjectStorageError::INVALID_ARGUMENT, fn () => $this->storage->list($refs[0], $limit));
        }
        $this->assertError(ObjectStorageError::INVALID_REFERENCE, fn () => $this->storage->list($refs[0], 2, '../bad'));
        self::assertSame($calls, $this->shared->calls);
        $page = $this->storage->list($refs[0], 1);
        $this->context->setTenant($this->b);
        $b = $this->storage->allocate();
        $calls = $this->shared->calls;
        $this->assertError(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->storage->list($b, 1, $page->nextCursor));
        self::assertSame($calls, $this->shared->calls);
    }

    public function testListingRejectsForeignMalformedOversizedOrUnorderedBackendResults(): void
    {
        $scope = $this->storage->allocate();
        $prefix = 'objects/v1/'.$scope->tenantNamespace.'/';
        foreach ([new BackendObjectPage(['foreign/'.str_repeat('c', 64)]), new BackendObjectPage([$prefix.'../secret']),
            new BackendObjectPage([], true), new BackendObjectPage([$prefix.str_repeat('b', 64), $prefix.str_repeat('a', 64)]),
            new BackendObjectPage([$prefix.str_repeat('a', 64), $prefix.str_repeat('a', 64)]),
            new BackendObjectPage([$prefix.str_repeat('a', 64), $prefix.str_repeat('b', 64), $prefix.str_repeat('c', 64)])] as $badPage) {
            $this->shared->page = $badPage;
            $this->assertError(ObjectStorageError::BACKEND_FAILURE, fn () => $this->storage->list($scope, 2));
        }
    }

    public static function outcomes(): iterable
    {
        foreach (OperationOutcome::cases() as $outcome) {
            yield $outcome->value => [$outcome];
        }
    }

    #[DataProvider('outcomes')]
    public function testBackendOutcomesPropagateWithoutSourceCleanupOrFallback(OperationOutcome $outcome): void
    {
        $source = $this->storage->allocate();
        $destination = $this->storage->allocate();
        $this->shared->failure = new ObjectStorageBackendException($outcome);
        foreach (['copy', 'move', 'exists'] as $method) {
            try {
                'exists' === $method ? $this->storage->exists($source) : $this->storage->$method($source, $destination);
                self::fail('Backend outcome must propagate.');
            } catch (ObjectStorageBackendException $exception) {
                self::assertSame($outcome, $exception->outcome);
            }
        }
        self::assertSame(['copy', 'move', 'exists'], array_column($this->shared->calls, 0));
        self::assertSame([], $this->dedicated->calls);
    }

    public function testUnexpectedBackendFailureIsSanitizedAndNeverFalse(): void
    {
        $ref = $this->storage->allocate();
        $this->shared->failure = new \RuntimeException('synthetic credential path diagnostic');
        try {
            $this->storage->exists($ref);
            self::fail('Unavailable is not absent.');
        } catch (ObjectStorageBackendException $exception) {
            self::assertSame(OperationOutcome::UNKNOWN, $exception->outcome);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('credential', $exception->getMessage());
        }
    }

    public function testTemporaryUrlPolicyAndBackendExpirationAreEnforced(): void
    {
        $ref = $this->storage->allocate();
        $url = $this->storage->temporaryUrl($ref, 30);
        self::assertGreaterThan(new \DateTimeImmutable(), $url->expiresAt);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+30 seconds'), $url->expiresAt);
        $calls = $this->shared->calls;
        foreach ([0, -1, 901] as $ttl) {
            $this->assertError(ObjectStorageError::INVALID_ARGUMENT, fn () => $this->storage->temporaryUrl($ref, $ttl));
        }
        self::assertSame($calls, $this->shared->calls);
        foreach (['-1 second', '+901 seconds'] as $expiry) {
            $this->shared->forcedExpiry = new \DateTimeImmutable($expiry);
            $this->assertError(ObjectStorageError::BACKEND_FAILURE, fn () => $this->storage->temporaryUrl($ref));
        }
        $other = $this->facade(new ConfiguredTenantStorageProviderSelector('dedicated'))->allocate();
        $this->assertError(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->temporaryUrl($other));
        self::assertSame([], $this->dedicated->calls);
        $disabled = new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'), $this->namespaces, $this->registry);
        $this->assertError(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $disabled->temporaryUrl($ref));
    }

    public function testStreamsAreSynchronousNonSeekableAndDoNotTransferOwnership(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[0], 'non-seekable payload');
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        self::assertFalse(stream_get_meta_data($pair[1])['seekable']);
        $ref = $this->storage->allocate();
        $this->storage->writeFromStream($ref, $pair[1]);
        self::assertTrue(is_resource($pair[1]));
        self::assertSame('non-seekable payload', $this->storage->read($ref));
        $this->assertError(ObjectStorageError::CONTEXT_CHANGED, fn () => $this->shared->retainedStream->readChunk());
        $destination = fopen('php://temp', 'w+');
        fwrite($destination, 'prefix:');
        $this->storage->readToStream($ref, $destination);
        rewind($destination);
        self::assertSame('prefix:non-seekable payload', stream_get_contents($destination));
        $this->assertError(ObjectStorageError::CONTEXT_CHANGED, fn () => $this->shared->retainedStream->writeChunk('late'));
        fclose($destination);
        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testResetOrTenantSwitchInterruptsStreamsEvenWhenReturningToA(): void
    {
        $ref = $this->storage->allocate();
        $this->storage->write($ref, str_repeat('x', 140000));
        foreach (['switch', 'reset', 'aba'] as $transition) {
            $this->context->setTenant($this->a);
            $this->shared->duringIo = function () use ($transition): void {
                if ('reset' === $transition) {
                    $this->storage->reset();
                } else {
                    $this->context->setTenant($this->b);
                    if ('aba' === $transition) {
                        $this->context->setTenant($this->a);
                    }
                }
            };
            $destination = fopen('php://temp', 'w+');
            $this->assertError(ObjectStorageError::BACKEND_FAILURE, fn () => $this->storage->readToStream($ref, $destination));
            self::assertSame(0, ftell($destination));
            self::assertTrue(is_resource($destination));
            $this->assertError(ObjectStorageError::CONTEXT_CHANGED, fn () => $this->shared->retainedStream->writeChunk('late'));
            fclose($destination);
        }
        $this->shared->duringIo = null;
        $this->storage->reset();
        $this->storage->reset();
        $this->context->setTenant($this->a);
        self::assertSame(str_repeat('x', 140000), $this->storage->read($ref));
    }

    public function testContextSwitchDuringActualStreamIoStopsFurtherChunks(): void
    {
        $class = \Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InterruptingStream::class;
        self::assertTrue(stream_wrapper_register('mtbobjecttest', $class));
        $reference = $this->storage->allocate();
        $this->storage->write($reference, str_repeat('x', 140000));
        try {
            foreach (['readToStream' => 'w', 'writeFromStream' => 'r'] as $operation => $mode) {
                $this->context->setTenant($this->a);
                $class::$written = '';
                $class::$eof = false;
                $class::$onIo = fn () => $this->context->setTenant($this->b);
                $stream = fopen('mtbobjecttest://caller', $mode);
                try {
                    $this->storage->$operation($reference, $stream);
                    self::fail('Mid-stream context switch must interrupt the operation.');
                } catch (ObjectStorageBackendException $e) {
                    self::assertSame(OperationOutcome::UNKNOWN, $e->outcome);
                    self::assertLessThanOrEqual(65536, strlen($class::$written));
                    self::assertTrue(is_resource($stream));
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            stream_wrapper_unregister('mtbobjecttest');
            $class::$onIo = null;
        }
    }

    public function testBindingFailureOutcomesReflectWhetherIoWasEntered(): void
    {
        $reference = $this->storage->allocate();
        $this->shared->bindingFailure = true;
        try {
            $this->storage->write($reference, 'content');
            self::fail('Binding failure must prevent I/O.');
        } catch (ObjectStorageBackendException $e) {
            self::assertSame(OperationOutcome::NOT_APPLIED, $e->outcome);
            self::assertSame([], $this->shared->calls);
        }
        $this->shared->bindingFailure = false;
        $this->shared->duringIo = function (): void { $this->shared->bindingFailure = true; };
        try {
            $this->storage->write($reference, 'content');
            self::fail('Post-I/O binding failure must not claim NOT_APPLIED.');
        } catch (ObjectStorageBackendException $e) {
            self::assertSame(OperationOutcome::UNKNOWN, $e->outcome);
            self::assertCount(1, $this->shared->calls);
        }
    }

    public function testReferenceSerializationForDoctrineAndMessengerIsStable(): void
    {
        $ref = $this->storage->allocate();
        self::assertSame($ref->toJson(), (string) $ref);
        self::assertSame($ref->toJson(), json_encode($ref, JSON_THROW_ON_ERROR));
        self::assertTrue($ref->equals(StoredObjectReference::fromJson($ref->toJson())));
        self::assertTrue($ref->equals(StoredObjectReference::fromArray($ref->toArray())));
        self::assertTrue($ref->equals(unserialize(serialize($ref))));
        $serializer = new PhpSerializer();
        $decoded = $serializer->decode($serializer->encode(new Envelope($ref, [new TenantStamp('1')])));
        self::assertTrue($ref->equals($decoded->getMessage()));
        self::assertSame('1', $decoded->last(TenantStamp::class)->getTenantId());
    }

    public static function malformedReferences(): iterable
    {
        foreach (['', '../secret', '/absolute', '%2e%2e', '%252f', 'a/b', 'a\\b', "a\0", 'a//b', 'objects/v1/'.str_repeat('a', 64), 'name@example.invalid', 'file.pdf', str_repeat('A', 64)] as $key) {
            yield 'key '.bin2hex($key) => [['key' => $key]];
            yield 'namespace '.bin2hex($key) => [['tenantNamespace' => $key]];
        }
        yield 'version' => [['formatVersion' => 2]];
        yield 'string version' => [['formatVersion' => '1']];
        yield 'location' => [['locationId' => '']];
        yield 'binding' => [['locationBinding' => 'physical-bucket']];
        yield 'extra target' => [['endpoint' => 'forbidden']];
    }

    #[DataProvider('malformedReferences')]
    public function testMalformedReferenceRejectedAtDeserialization(array $changes): void
    {
        $data = array_replace($this->storage->allocate()->toArray(), $changes);
        $this->assertError(ObjectStorageError::INVALID_REFERENCE, fn () => StoredObjectReference::fromArray($data));
        self::assertSame([], $this->shared->calls);
    }

    public function testPhysicalIdentityIsCanonicalAndNamespaceMapMustBeUnique(): void
    {
        $first = new PhysicalStorageIdentity('synthetic', 'endpoint', 'root', ['z' => '1', 'a' => '2']);
        $second = new PhysicalStorageIdentity('synthetic', 'endpoint', 'root', ['a' => '2', 'z' => '1']);
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertNotSame($first->fingerprint(), (new PhysicalStorageIdentity('synthetic', 'endpoint', 'other', ['a' => '2', 'z' => '1']))->fingerprint());
        $this->assertError(ObjectStorageError::INVALID_ARGUMENT, fn () => new ConfiguredTenantStorageNamespaceResolver(['1' => str_repeat('a', 64), '2' => str_repeat('a', 64)]));
    }
}
