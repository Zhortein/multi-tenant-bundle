<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\LogicalObjectIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservationState;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationDescriptor;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationRegistration;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\AuditBackend;

final class ObjectStorageAuditTest extends TestCase
{
    private TenantContext $context;
    private TenantObjectStorage $storage;
    private ObjectStorageRegistry $registry;
    private ObjectStorageAuditCodec $codec;
    private TestTenant $a;
    private TestTenant $b;

    protected function setUp(): void
    {
        $this->a = (new TestTenant())->setId(1)->setSlug('same');
        $this->b = (new TestTenant())->setId(2)->setSlug('same');
        $this->context = new TenantContext();
        $this->context->setTenant($this->a);
        $this->codec = new ObjectStorageAuditCodec('test', ['test' => str_repeat('a', 64)]);
        $locations = [];
        foreach (['shared_v2', 'shared_v0', 'dedicated_v1', 'shared_v1'] as $id) {
            $provider = str_starts_with($id, 'shared') ? 'shared' : 'dedicated';
            $allowed = 'shared' === $provider ? ['*'] : ['1'];
            $locations[] = new StorageLocationRegistration(new StorageLocationDescriptor($id, $provider, in_array($id, ['shared_v2', 'dedicated_v1'], true), true, true), $allowed,
                static function () use ($id, $allowed): StorageLocation {
                    $backend = new AuditBackend($id);

                    return new StorageLocation($id, $backend, $backend, $allowed);
                });
        }
        $this->registry = new ObjectStorageRegistry($locations, ['shared' => 'shared_v2', 'dedicated' => 'dedicated_v1'], $this->codec);
        $this->storage = new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'),
            new ConfiguredTenantStorageNamespaceResolver(['1' => str_repeat('a', 64), '2' => str_repeat('b', 64)]), $this->registry, auditCodec: $this->codec);
    }

    private function reference(string $key = '1', string $location = 'shared_v2'): StoredObjectReference
    {
        $scope = $this->storage->auditScope($location);

        return new StoredObjectReference($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, str_repeat($key, 64));
    }

    private function backend(string $location = 'shared_v2'): AuditBackend
    {
        $backend = $this->registry->location($location)->backend;
        self::assertInstanceOf(AuditBackend::class, $backend);

        return $backend;
    }

    public function testInventoryIsLazyBoundedStableTenantSpecificAndIncludesAllHistory(): void
    {
        $before = AuditBackend::$constructions;
        $first = $this->storage->inventoryLocations(2);
        self::assertSame(['dedicated_v1', 'shared_v0'], array_column($first->locations, 'locationId'));
        self::assertNotNull($first->nextCursor);
        $second = $this->storage->inventoryLocations(2, $first->nextCursor);
        self::assertSame(['shared_v1', 'shared_v2'], array_column($second->locations, 'locationId'));
        self::assertNull($second->nextCursor);
        self::assertSame([false, true], array_column($second->locations, 'active'));
        $this->context->setTenant($this->b);
        self::assertSame(['shared_v0', 'shared_v1', 'shared_v2'], array_column($this->storage->inventoryLocations()->locations, 'locationId'));
        $this->context->setTenant($this->a);
        $this->storage->reset();
        self::assertEquals($first->locations, $this->storage->inventoryLocations(2)->locations);
        self::assertSame($before, AuditBackend::$constructions);
        $empty = new ObjectStorageRegistry([], [], $this->codec);
        self::assertSame([], $empty->inventory($this->a)->locations);
        self::assertNull($empty->inventory($this->a)->nextCursor);
    }

    public function testUnknownForbiddenAndMissingTenantDoNotInstantiateAnyBackend(): void
    {
        $before = AuditBackend::$constructions;
        $this->context->setTenant($this->b);
        foreach (['dedicated_v1', 'unknown'] as $id) {
            try {
                $this->storage->auditScope($id);
                self::fail('Forbidden or unknown location.');
            } catch (ObjectStorageException $e) {
                self::assertContains($e->reason, [ObjectStorageError::TENANT_NOT_ALLOWED, ObjectStorageError::UNKNOWN_LOCATION]);
            }
        }
        $this->context->clear();
        try {
            $this->storage->inventoryLocations();
            self::fail('Tenant required.');
        } catch (ObjectStorageException $e) {
            self::assertSame(ObjectStorageError::MISSING_CONTEXT, $e->reason);
        }
        self::assertSame($before, AuditBackend::$constructions);
    }

    public function testInventoryCursorRejectsTenantLimitRevisionAndTampering(): void
    {
        $cursor = $this->storage->inventoryLocations(1)->nextCursor;
        self::assertNotNull($cursor);
        foreach (['tenant', 'limit', 'tampering'] as $change) {
            $this->context->setTenant('tenant' === $change ? $this->b : $this->a);
            try {
                $this->storage->inventoryLocations('limit' === $change ? 2 : 1, 'tampering' === $change ? $cursor.'x' : $cursor);
                self::fail('Changed pagination scope.');
            } catch (ObjectStorageException $e) {
                self::assertSame(ObjectStorageError::INVALID_REFERENCE, $e->reason);
                self::assertNull($e->getPrevious());
            }
        }
    }

    public function testIdentityWriteReadCopyMoveOverwriteAndRepeatedDeletePreserveRc11Addresses(): void
    {
        $ref = $this->reference();
        $json = $ref->toJson();
        $identity = LogicalObjectIdentity::forReference($ref, str_repeat('e', 64));
        $this->storage->writeWithIdentity($ref, 'content', $identity);
        self::assertSame('content', $this->storage->read($ref));
        self::assertSame($json, $ref->toJson());
        self::assertTrue(StoredObjectReference::fromJson($json)->equals($ref));
        $observation = $this->storage->observe($ref);
        self::assertSame(ObjectObservationState::VERIFIED, $observation->state);
        self::assertTrue($observation->identity->matchesReference($ref));
        self::assertSame('shared_v2', $observation->identity->generation);
        $copy = $this->reference('2');
        $move = $this->reference('3');
        $this->storage->copy($ref, $copy);
        $this->storage->move($copy, $move);
        self::assertEquals($identity, $this->storage->observe($move)->identity);
        self::assertFalse($identity->matchesReference($move));
        self::assertTrue($identity->sameLogicalObject($this->storage->observe($move)->identity));
        self::assertSame(ObjectObservationState::INDETERMINATE, $this->storage->observe($copy)->state);
        self::assertSame(['objects/v1/'.$ref->tenantNamespace.'/'.$ref->key, 'objects/v1/'.$move->tenantNamespace.'/'.$move->key], array_keys($this->backend()->objects));
        $this->storage->write($ref, 'legacy replacement');
        self::assertSame(ObjectObservationState::IDENTITY_ABSENT, $this->storage->observe($ref)->state);
        $this->storage->delete($move);
        $this->storage->delete($move);
    }

    public function testStreamIdentityUsesCallerPositionAndInvalidatesRetainedStream(): void
    {
        $ref = $this->reference();
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'ignore'.str_repeat('x', 180000));
            fseek($stream, 6);
            $this->storage->writeFromStreamWithIdentity($ref, $stream, LogicalObjectIdentity::forReference($ref, str_repeat('e', 64)));
            self::assertSame(str_repeat('x', 180000), $this->storage->read($ref));
            self::assertSame(ObjectObservationState::VERIFIED, $this->storage->observe($ref)->state);
            self::assertIsResource($stream);
            $this->expectException(ObjectStorageException::class);
            $this->backend()->retainedStream->readChunk();
        } finally {
            fclose($stream);
        }
    }

    public function testPaginationContinuesAfterIndividualAnomaliesWithoutReturningRawKeys(): void
    {
        $ref = $this->reference('1');
        $scope = $this->storage->auditScope('shared_v2');
        $identity = LogicalObjectIdentity::forReference($ref, str_repeat('e', 64));
        $this->storage->writeWithIdentity($ref, 'one', $identity);
        $second = $this->reference('2');
        $this->storage->write($second, 'historical');
        $prefix = 'objects/v1/'.$ref->tenantNamespace.'/';
        $invalid = $prefix.'2-malformed-secret-name';
        $this->backend()->objects[$invalid] = 'malformed';
        $third = $this->reference('3');
        $this->storage->write($third, 'invalid metadata');
        $this->backend()->envelopes[$prefix.$third->key] = 'private endpoint credential';
        $fourth = $this->reference('4');
        $this->storage->writeWithIdentity($fourth, 'valid again', $identity);
        $states = [];
        $cursor = null;
        do {
            $page = $this->storage->auditList($scope, 1, $cursor);
            self::assertCount(1, $page->observations);
            self::assertStringNotContainsString('malformed-secret-name', json_encode($page));
            self::assertStringNotContainsString('credential', json_encode($page));
            $states[] = $page->observations[0]->state;
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        self::assertSame([ObjectObservationState::VERIFIED, ObjectObservationState::INVALID_REFERENCE, ObjectObservationState::IDENTITY_ABSENT, ObjectObservationState::IDENTITY_INVALID, ObjectObservationState::VERIFIED], $states);
        self::assertNotContains($invalid, array_column(array_filter($this->backend()->calls, static fn ($c) => 'observeIdentity' === $c[0]), 1));
        $this->expectException(ObjectStorageException::class);
        $this->storage->list($ref); // The RC11 strict contract remains strict.
    }

    public function testForeignAuthenticatedIdentityIsRedactedAndForeignPhysicalKeyFailsThePage(): void
    {
        $ref = $this->reference();
        $this->storage->write($ref, 'one');
        $this->context->setTenant($this->b);
        $foreign = $this->reference();
        $identity = LogicalObjectIdentity::forReference($foreign, str_repeat('e', 64));
        $this->storage->writeWithIdentity($foreign, 'foreign', $identity);
        $backend = $this->backend();
        $aKey = 'objects/v1/'.$ref->tenantNamespace.'/'.$ref->key;
        $bKey = 'objects/v1/'.$foreign->tenantNamespace.'/'.$foreign->key;
        $backend->envelopes[$aKey] = $backend->envelopes[$bKey];
        $this->context->setTenant($this->a);
        $observation = $this->storage->observe($ref);
        self::assertSame(ObjectObservationState::FOREIGN, $observation->state);
        self::assertNull($observation->reference);
        self::assertNull($observation->identity);
        self::assertStringNotContainsString($foreign->tenantNamespace, json_encode($observation));
        $backend->page = new BackendObjectPage([$aKey, $bKey]);
        $calls = count($backend->calls);
        try {
            $this->storage->auditList($this->storage->auditScope('shared_v2'));
            self::fail('Foreign physical key must fail closed.');
        } catch (ObjectStorageException $e) {
            self::assertSame(ObjectStorageError::BACKEND_FAILURE, $e->reason);
            self::assertNull($e->getPrevious());
        }
        self::assertCount($calls + 1, $backend->calls); // No per-entry HEAD before page validation.
    }

    public function testDisappearanceNetworkFailureAndContextChangeHaveDifferentOutcomes(): void
    {
        $ref = $this->reference();
        $key = 'objects/v1/'.$ref->tenantNamespace.'/'.$ref->key;
        $backend = $this->backend();
        $backend->page = new BackendObjectPage([$key]);
        $scope = $this->storage->auditScope('shared_v2');
        self::assertSame(ObjectObservationState::INDETERMINATE, $this->storage->auditList($scope)->observations[0]->state);
        foreach (['network endpoint secret', 'TLS verification failed credential'] as $failure) {
            $backend->observationFailures[$key] = new \RuntimeException($failure);
            $observation = $this->storage->auditList($scope)->observations[0];
            self::assertSame(ObjectObservationState::UNREACHABLE, $observation->state);
            self::assertStringNotContainsString($failure, json_encode($observation));
        }
        $backend->duringIo = fn () => $this->context->setTenant($this->b);
        $this->expectException(ObjectStorageException::class);
        $this->storage->auditList($scope);
    }

    public function testCredentialRotationKeepsIdentityButTargetChangeIsRejectedBeforeIo(): void
    {
        $ref = $this->reference();
        $identity = LogicalObjectIdentity::forReference($ref, str_repeat('e', 64));
        $this->storage->writeWithIdentity($ref, 'one', $identity);
        $this->backend()->credentials = 'rotated';
        self::assertEquals($identity, $this->storage->observe($ref)->identity);
        $calls = $this->backend()->calls;
        $this->backend()->target = 'changed';
        try {
            $this->storage->observe($ref);
            self::fail('Changed target must be rejected.');
        } catch (ObjectStorageException $e) {
            self::assertSame(ObjectStorageError::BINDING_MISMATCH, $e->reason);
        }
        self::assertSame($calls, $this->backend()->calls);
    }

    public static function invalidIdentities(): iterable
    {
        yield 'long' => [['correlationId' => str_repeat('a', 65)]];
        yield 'short' => [['correlationId' => 'ab']];
        yield 'uppercase' => [['correlationId' => str_repeat('A', 64)]];
        yield 'filename' => [['correlationId' => 'personal-file.pdf']];
        yield 'version' => [['version' => 2]];
        yield 'type' => [['version' => '1']];
        yield 'structure' => [['unexpected' => true]];
        yield 'generation' => [['generation' => '../private']];
    }

    #[DataProvider('invalidIdentities')]
    public function testInvalidIdentityNeverBecomesVerified(array $changes): void
    {
        $ref = $this->reference();
        $data = array_replace(LogicalObjectIdentity::forReference($ref, str_repeat('e', 64))->toArray(), $changes);
        $this->storage->write($ref, 'one');
        $this->backend()->envelopes['objects/v1/'.$ref->tenantNamespace.'/'.$ref->key] = $this->codec->seal($data, 'identity');
        self::assertSame(ObjectObservationState::IDENTITY_INVALID, $this->storage->observe($ref)->state);
        $this->expectException(ObjectStorageException::class);
        LogicalObjectIdentity::fromArray($data);
    }

    public function testCodecRotationPurposeTamperingAndSecretRedaction(): void
    {
        $old = $this->codec->seal(['test' => 'opaque'], 'identity');
        $rotated = new ObjectStorageAuditCodec('next', ['test' => str_repeat('a', 64), 'next' => str_repeat('b', 64)]);
        self::assertSame(['test' => 'opaque'], $rotated->open($old, 'identity'));
        foreach ([$old.'x', str_repeat('x', 7000), str_replace('a1.', 'a2.', $old)] as $bad) {
            try {
                $rotated->open($bad, 'identity');
                self::fail('Invalid envelope.');
            } catch (ObjectStorageException $e) {
                self::assertNull($e->getPrevious());
            }
        }
        self::assertSame([], $rotated->__debugInfo());
        $this->expectException(ObjectStorageException::class);
        $rotated->open($old, 'objects');
    }

    public function testDuplicateAndInconsistentRegistrationsAreRejectedWithoutResolvingFactories(): void
    {
        $calls = 0;
        $factory = static function () use (&$calls): StorageLocation {
            ++$calls;
            throw new \RuntimeException('Must not instantiate.');
        };
        $registration = new StorageLocationRegistration(new StorageLocationDescriptor('shared_v1', 'shared', true), ['*'], $factory);
        foreach ([[$registration, $registration], [new StorageLocationRegistration(new StorageLocationDescriptor('shared_v1', 'unknown', true), ['*'], $factory)],
            [new StorageLocationRegistration(new StorageLocationDescriptor('shared_v1', 'shared', false), ['*'], $factory)],
        ] as $locations) {
            try {
                new ObjectStorageRegistry($locations, ['shared' => 'shared_v1'], $this->codec);
                self::fail('Registry inconsistency.');
            } catch (ObjectStorageException $e) {
                self::assertSame(ObjectStorageError::INVALID_ARGUMENT, $e->reason);
            }
        }
        self::assertSame(0, $calls);
        $tenant = $this->createStub(\Zhortein\MultiTenantBundle\Entity\TenantInterface::class);
        $tenant->method('getId')->willReturn('');
        $this->expectException(ObjectStorageException::class);
        $this->registry->inventory($tenant);
    }

    public function testLegacyBackendHasExplicitUnavailableIdentityAndUnsupportedAuditListing(): void
    {
        $backend = new \Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InstrumentedBackend();
        $location = new StorageLocation('legacy', $backend, $backend, ['*']);
        $registry = new ObjectStorageRegistry([new StorageLocationRegistration(new StorageLocationDescriptor('legacy', 'shared', true), ['*'], static fn () => $location)], ['shared' => 'legacy'], $this->codec);
        $storage = new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'), new ConfiguredTenantStorageNamespaceResolver(['1' => str_repeat('a', 64)]), $registry, auditCodec: $this->codec);
        $reference = $storage->allocate();
        $storage->write($reference, 'legacy');
        $calls = $backend->calls;
        self::assertSame(ObjectObservationState::UNAVAILABLE, $storage->observe($reference)->state);
        self::assertFalse($storage->inventoryLocations()->locations[0]->auditListing);
        self::assertSame($calls, $backend->calls);
        $this->expectException(ObjectStorageException::class);
        $storage->auditList($storage->auditScope('legacy'));
    }

    public function testObjectCursorIsBoundToTenantLocationBindingRevisionLimitAndIntegrity(): void
    {
        $first = $this->reference('1');
        $this->storage->write($first, 'one');
        $this->storage->write($this->reference('2'), 'two');
        $scope = $this->storage->auditScope('shared_v2');
        $cursor = $this->storage->auditList($scope, 1)->nextCursor;
        self::assertNotNull($cursor);
        $calls = $this->backend()->calls;
        foreach (['tenant', 'location', 'revision', 'limit', 'integrity'] as $change) {
            $this->context->setTenant('tenant' === $change ? $this->b : $this->a);
            $changedScope = match ($change) {
                'location' => $this->storage->auditScope('shared_v1'),
                'revision' => new \Zhortein\MultiTenantBundle\ObjectStorage\StorageAuditScope($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, str_repeat('f', 64)),
                'tenant' => $this->storage->auditScope('shared_v2'),
                default => $scope,
            };
            try {
                $this->storage->auditList($changedScope, 'limit' === $change ? 2 : 1, 'integrity' === $change ? $cursor.'x' : $cursor);
                self::fail('Cursor must not be reusable outside its exact query.');
            } catch (ObjectStorageException $e) {
                self::assertSame(ObjectStorageError::INVALID_REFERENCE, $e->reason);
                self::assertNull($e->getPrevious());
            }
        }
        self::assertSame($calls, $this->backend()->calls);
        $this->context->setTenant($this->a);
        $this->backend()->target = 'different';
        $this->expectException(ObjectStorageException::class);
        $this->storage->auditList($scope, 1, $cursor);
    }

    public function testMalformedPaginationAndGlobalNetworkFailureNeverReturnPartialObservations(): void
    {
        $ref = $this->reference();
        $prefix = 'objects/v1/'.$ref->tenantNamespace.'/';
        $scope = $this->storage->auditScope('shared_v2');
        foreach ([new BackendObjectPage([], true), new BackendObjectPage([2 => $prefix.$ref->key]),
            new BackendObjectPage([$prefix.str_repeat('2', 64), $prefix.$ref->key]),
            new BackendObjectPage([$prefix.$ref->key, $prefix.$ref->key]),
            new BackendObjectPage([$prefix.str_repeat('x', 1024)]),
        ] as $page) {
            $this->backend()->page = $page;
            $calls = count($this->backend()->calls);
            try {
                $this->storage->auditList($scope, 2);
                self::fail('Malformed global pagination.');
            } catch (ObjectStorageException $e) {
                self::assertSame(ObjectStorageError::BACKEND_FAILURE, $e->reason);
            }
            self::assertCount($calls + 1, $this->backend()->calls);
        }
        $this->backend()->failure = new \RuntimeException('TLS credential endpoint');
        $this->expectException(ObjectStorageException::class);
        $this->expectExceptionMessage('Object storage: backend_failure.');
        $this->storage->auditList($scope);
    }

    public function testForeignIdentityWritesAreRejectedBeforeAnyIo(): void
    {
        $ref = $this->reference();
        $this->context->setTenant($this->b);
        $foreign = LogicalObjectIdentity::forReference($this->reference(), str_repeat('e', 64));
        $this->context->setTenant($this->a);
        $stream = fopen('php://temp', 'w+b');
        try {
            foreach (['content', 'stream'] as $kind) {
                try {
                    'content' === $kind ? $this->storage->writeWithIdentity($ref, 'forbidden', $foreign) : $this->storage->writeFromStreamWithIdentity($ref, $stream, $foreign);
                    self::fail('Foreign logical identity.');
                } catch (ObjectStorageException $e) {
                    self::assertSame(ObjectStorageError::FOREIGN_REFERENCE, $e->reason);
                }
            }
        } finally {
            fclose($stream);
        }
        self::assertSame([], $this->backend()->calls);
    }

    public function testIdentitySerializationIsValidatedAndInvalidLongKeyCursorRemainsBounded(): void
    {
        $ref = $this->reference();
        $identity = LogicalObjectIdentity::forReference($ref, str_repeat('e', 64));
        self::assertEquals($identity, unserialize(serialize($identity)));
        self::assertEquals($identity, LogicalObjectIdentity::fromArray(array_reverse($identity->toArray(), true)));
        $prefix = 'objects/v1/'.$ref->tenantNamespace.'/';
        $this->backend()->objects[$prefix.str_repeat("\x01", 900)] = 'malformed';
        $this->storage->write($ref, 'historical');
        $scope = $this->storage->auditScope('shared_v2');
        $page = $this->storage->auditList($scope, 1);
        self::assertSame(ObjectObservationState::INVALID_REFERENCE, $page->observations[0]->state);
        self::assertLessThanOrEqual(12288, strlen($page->nextCursor));
        self::assertSame(ObjectObservationState::IDENTITY_ABSENT, $this->storage->auditList($scope, 1, $page->nextCursor)->observations[0]->state);
        $this->expectException(ObjectStorageException::class);
        unserialize(str_replace('s:7:"version";i:1;', 's:7:"version";i:2;', serialize($identity)));
    }
}
