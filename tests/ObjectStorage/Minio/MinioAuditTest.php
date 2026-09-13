<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\ObjectStorage\Minio;

use GuzzleHttp\Client;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
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

final class MinioAuditTest extends MinioTestCase
{
    private TenantContext $context;
    private ObjectStorageAuditCodec $codec;
    private TestTenant $a;
    private TestTenant $b;
    private int $constructions = 0;

    private function storage(string $access = self::ACCESS, string $secret = self::SECRET): TenantObjectStorage
    {
        $locations = [];
        foreach (['shared_v1' => 'shared', 'shared_v2' => 'generation', 'dedicated_v1' => 'dedicated'] as $id => $bucket) {
            $provider = 'dedicated' === $bucket ? 'dedicated' : 'shared';
            $allowed = 'dedicated' === $bucket ? ['1'] : ['*'];
            $locations[] = new StorageLocationRegistration(new StorageLocationDescriptor($id, $provider, 'shared_v1' !== $id, true, true), $allowed,
                function () use ($id, $bucket, $allowed, $access, $secret): StorageLocation {
                    ++$this->constructions;
                    $backend = S3CompatibleStorageFactory::create($this->config($bucket), $access, $secret, true, getenv('MTB_OBJECT_CA'), true);

                    return new StorageLocation($id, $backend, $backend, $allowed, true);
                });
        }
        $registry = new ObjectStorageRegistry($locations, ['shared' => 'shared_v2', 'dedicated' => 'dedicated_v1'], $this->codec);

        return new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'),
            new ConfiguredTenantStorageNamespaceResolver(['1' => str_repeat('a', 64), '2' => str_repeat('b', 64)]), $registry, true, auditCodec: $this->codec);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new TenantContext();
        $this->a = (new TestTenant())->setId(1)->setSlug('same');
        $this->b = (new TestTenant())->setId(2)->setSlug('same');
        $this->context->setTenant($this->a);
        $this->codec = new ObjectStorageAuditCodec('test', ['test' => str_repeat('a', 64)]);
    }

    public function testRealInventoryMetadataHistoricalGenerationsCopiesStreamsAndPagination(): void
    {
        $storage = $this->storage();
        self::assertCount(3, $storage->inventoryLocations()->locations);
        self::assertSame(0, $this->constructions);
        $oldScope = $storage->auditScope('shared_v1');
        $old = new StoredObjectReference($oldScope->locationId, $oldScope->locationBinding, $oldScope->tenantNamespace, str_repeat('1', 64));
        $storage->write($old, 'RC11 historical');
        self::assertSame('RC11 historical', $storage->read($old));
        self::assertSame(ObjectObservationState::IDENTITY_ABSENT, $storage->observe($old)->state);
        $ref = $storage->allocate();
        $identity = LogicalObjectIdentity::forReference($ref, str_repeat('e', 64));
        $originalJson = $ref->toJson();
        $storage->writeWithIdentity($ref, 'new identity', $identity);
        self::assertSame($originalJson, $ref->toJson());
        self::assertEquals($identity, $storage->observe($ref)->identity);
        self::assertSame('new identity', $storage->read($ref));
        $key = 'technical/objects/v1/'.$ref->tenantNamespace.'/'.$ref->key;
        $metadata = $this->client->headObject(['Bucket' => $this->buckets['generation'], 'Key' => $key])['Metadata'];
        self::assertArrayHasKey(AuditableFlysystemBackend::IDENTITY_METADATA, $metadata);
        self::assertLessThan(2048, strlen($metadata[AuditableFlysystemBackend::IDENTITY_METADATA]));
        $copy = $storage->allocate();
        $moved = $storage->allocate();
        $storage->copy($ref, $copy);
        $storage->move($copy, $moved);
        self::assertEquals($identity, $storage->observe($moved)->identity);
        self::assertContains($storage->observe($copy)->state, [ObjectObservationState::INDETERMINATE, ObjectObservationState::UNREACHABLE]);
        $stream = fopen('php://temp/maxmemory:65536', 'w+b');
        try {
            fwrite($stream, 'ignore:'.str_repeat('x', 180000));
            fseek($stream, 7);
            $storage->writeFromStreamWithIdentity($copy, $stream, $identity);
            self::assertIsResource($stream);
            self::assertSame(str_repeat('x', 180000), $storage->read($copy));
            self::assertEquals($identity, $storage->observe($copy)->identity);
        } finally {
            fclose($stream);
        }
        $scope = $storage->auditScope('shared_v2');
        $cursor = null;
        $count = 0;
        do {
            $page = $storage->auditList($scope, 1, $cursor);
            self::assertCount(1, $page->observations);
            self::assertSame(ObjectObservationState::VERIFIED, $page->observations[0]->state);
            self::assertTrue($identity->sameLogicalObject($page->observations[0]->identity));
            ++$count;
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        self::assertSame(3, $count);
        // An operator's cross-generation copy preserves the original claim; it is not a bundle move API.
        $this->client->copyObject(['Bucket' => $this->buckets['shared'], 'Key' => $key, 'CopySource' => $this->buckets['generation'].'/'.$key]);
        $observedInOld = new StoredObjectReference($oldScope->locationId, $oldScope->locationBinding, $oldScope->tenantNamespace, $ref->key);
        self::assertTrue($storage->observe($observedInOld)->identity->matchesReference($ref));
        self::assertSame('shared_v2', $storage->observe($observedInOld)->identity->generation);
        self::assertSame('shared_v1', $observedInOld->locationId);
        $dedicated = $storage->auditScope('dedicated_v1');
        $dedicatedRef = new StoredObjectReference($dedicated->locationId, $dedicated->locationBinding, $dedicated->tenantNamespace, str_repeat('f', 64));
        $storage->writeWithIdentity($dedicatedRef, 'dedicated', $identity);
        self::assertEquals($identity, $storage->observe($dedicatedRef)->identity);
        $http = new Client(['verify' => getenv('MTB_OBJECT_CA')]);
        self::assertSame('new identity', (string) $http->get($storage->temporaryUrl($ref)->url)->getBody());
        $rotated = $this->storage('mtb-object-rotated', 'mtb-object-rotated-synthetic-password');
        self::assertEquals($identity, $rotated->observe($ref)->identity);
        $this->context->setTenant($this->b);
        self::assertCount(2, $storage->inventoryLocations()->locations);
        self::assertSame([], $storage->auditList($storage->auditScope('shared_v2'))->observations);
        $this->context->setTenant($this->a);
        self::assertEquals($identity, $storage->observe($ref)->identity);
        $storage->delete($copy);
        $storage->delete($copy);
        $storage->write($moved, 'legacy overwrite');
        self::assertSame(ObjectObservationState::IDENTITY_ABSENT, $storage->observe($moved)->state);
    }

    public function testRealAnomaliesRemainIndividualAndDoNotLeakOrPreventNextPage(): void
    {
        $storage = $this->storage();
        $scope = $storage->auditScope('shared_v2');
        $prefix = 'technical/objects/v1/'.$scope->tenantNamespace.'/';
        $foreign = new StoredObjectReference($scope->locationId, $scope->locationBinding, str_repeat('b', 64), str_repeat('9', 64));
        $foreignEnvelope = $this->codec->seal(LogicalObjectIdentity::forReference($foreign, str_repeat('e', 64))->toArray(), 'identity');
        foreach ([str_repeat('1', 64) => null, '2-private-name' => null, str_repeat('3', 64) => 'malformed', str_repeat('4', 64) => $foreignEnvelope] as $suffix => $envelope) {
            $this->client->putObject(['Bucket' => $this->buckets['generation'], 'Key' => $prefix.$suffix, 'Body' => 'synthetic',
                'Metadata' => null === $envelope ? [] : [AuditableFlysystemBackend::IDENTITY_METADATA => $envelope]]);
        }
        $states = [];
        $cursor = null;
        do {
            $page = $storage->auditList($scope, 1, $cursor);
            self::assertCount(1, $page->observations);
            $states[] = $page->observations[0]->state;
            foreach ([$this->buckets['generation'], 'private-name', $foreign->tenantNamespace, self::SECRET] as $sensitive) {
                self::assertStringNotContainsString($sensitive, json_encode($page));
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        self::assertSame([ObjectObservationState::IDENTITY_ABSENT, ObjectObservationState::INVALID_REFERENCE, ObjectObservationState::IDENTITY_INVALID, ObjectObservationState::FOREIGN], $states);
        $this->expectException(ObjectStorageException::class);
        $storage->list(new StoredObjectReference($scope->locationId, $scope->locationBinding, $scope->tenantNamespace, str_repeat('1', 64)));
    }

    public function testRealTlsAndNetworkFailuresAreSanitized(): void
    {
        foreach ([['https://minio:1', getenv('MTB_OBJECT_CA')], [getenv('MTB_OBJECT_ENDPOINT'), null]] as [$endpoint, $ca]) {
            $backend = S3CompatibleStorageFactory::create(new S3LocationConfiguration($endpoint, $this->buckets['shared']), self::ACCESS, self::SECRET, caBundle: $ca, audit: true);
            foreach (['auditList', 'observeIdentity'] as $method) {
                try {
                    $prefix = 'objects/v1/'.str_repeat('a', 64).'/';
                    'auditList' === $method ? $backend->auditList($prefix, 1) : $backend->observeIdentity($prefix.str_repeat('b', 64));
                    self::fail('Network/TLS must not become absence.');
                } catch (ObjectStorageException $e) {
                    self::assertSame('Object storage: backend_failure.', $e->getMessage());
                    self::assertNull($e->getPrevious());
                }
            }
        }
    }
}
