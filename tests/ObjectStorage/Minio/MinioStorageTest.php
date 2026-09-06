<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\ObjectStorage\Minio;

use GuzzleHttp\Client;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class MinioStorageTest extends MinioTestCase
{
    private TenantContext $context;
    private TenantObjectStorage $storage;
    private ConfiguredTenantStorageNamespaceResolver $namespaces;
    private ObjectStorageRegistry $registry;
    private array $backends;
    private TestTenant $a;
    private TestTenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = (new TestTenant())->setId(1)->setSlug('identical-slug');
        $this->b = (new TestTenant())->setId(2)->setSlug('identical-slug');
        $events = new EventDispatcher();
        $this->context = new TenantContext($events);
        $this->namespaces = new ConfiguredTenantStorageNamespaceResolver(['1' => bin2hex(random_bytes(32)), '2' => bin2hex(random_bytes(32))]);
        $this->backends = ['shared_v1' => new RecordingBackend($this->backend()), 'dedicated_v1' => $this->backend('dedicated', false), 'shared_v2' => new RecordingBackend($this->backend('generation'))];
        $this->registry = new ObjectStorageRegistry([
            new StorageLocation('shared_v1', $this->backends['shared_v1'], $this->backends['shared_v1'], ['*'], true),
            new StorageLocation('dedicated_v1', $this->backends['dedicated_v1'], $this->backends['dedicated_v1'], ['1']),
            new StorageLocation('shared_v2', $this->backends['shared_v2'], $this->backends['shared_v2'], ['*'], true),
        ], ['shared' => 'shared_v1', 'dedicated' => 'dedicated_v1', 'next' => 'shared_v2']);
        $this->storage = $this->facade();
        $events->addSubscriber($this->storage);
        $this->context->setTenant($this->a);
    }

    private function facade(string $provider = 'shared', array $overrides = []): TenantObjectStorage
    {
        return new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector($provider, $overrides), $this->namespaces, $this->registry, true);
    }

    private function rejected(ObjectStorageError $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Operation must be rejected.');
        } catch (ObjectStorageException $e) {
            self::assertSame($reason, $e->reason);
            self::assertNull($e->getPrevious());
            self::assertSame('Object storage: '.$reason->value.'.', $e->getMessage());
        }
    }

    public function testSharedBucketStreamsPaginationPrivateObjectsAndEveryPrimitive(): void
    {
        $reference = $this->storage->allocate();
        self::assertFalse($this->storage->exists($reference));
        $this->storage->write($reference, 'initial');
        self::assertSame('initial', $this->storage->read($reference));
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[0], 'non-seekable upload');
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        self::assertFalse(stream_get_meta_data($pair[1])['seekable']);
        try {
            $this->storage->writeFromStream($reference, $pair[1]);
            self::assertTrue(is_resource($pair[1]));
            self::assertSame('non-seekable upload', $this->storage->read($reference));
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
        $content = str_repeat('bounded-copy-', 24000);
        $this->storage->write($reference, $content);
        $destination = fopen('php://temp/maxmemory:65536', 'w+b');
        try {
            fwrite($destination, 'prefix:');
            $this->storage->readToStream($reference, $destination);
            self::assertTrue(is_resource($destination));
            rewind($destination);
            self::assertSame('prefix:'.$content, stream_get_contents($destination));
        } finally {
            fclose($destination);
        }
        $metadata = $this->storage->metadata($reference);
        self::assertSame(strlen($content), $metadata->size);
        self::assertNotNull($metadata->lastModified);
        $copy = $this->storage->allocate();
        $moved = $this->storage->allocate();
        $this->storage->copy($reference, $copy);
        $this->storage->move($copy, $moved);
        self::assertSame($content, $this->storage->read($moved));
        self::assertFalse($this->storage->exists($copy));
        $this->context->setTenant($this->b);
        $b = $this->storage->allocate();
        $b = new StoredObjectReference($b->locationId, $b->locationBinding, $b->tenantNamespace, $reference->key);
        $this->storage->write($b, 'neighbor');
        $this->context->setTenant($this->a);
        $found = [];
        $cursor = null;
        do {
            $page = $this->storage->list($reference, 1, $cursor);
            self::assertCount(1, $page->references);
            foreach ($page->references as $item) {
                self::assertSame($reference->tenantNamespace, $item->tenantNamespace);
                $found[] = $item->key;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        self::assertCount(2, array_unique($found));
        $http = new Client(['verify' => getenv('MTB_OBJECT_CA'), 'http_errors' => false]);
        $unsigned = getenv('MTB_OBJECT_ENDPOINT').'/'.$this->buckets['shared'].'/technical/objects/v1/'.$reference->tenantNamespace.'/'.$reference->key;
        self::assertSame(403, $http->get($unsigned)->getStatusCode());
        self::assertSame(403, $http->get(getenv('MTB_OBJECT_ENDPOINT').'/'.$this->buckets['shared'])->getStatusCode());
        $acl = $this->client->getObjectAcl(['Bucket' => $this->buckets['shared'], 'Key' => 'technical/objects/v1/'.$reference->tenantNamespace.'/'.$reference->key]);
        self::assertCount(1, $acl['Grants']);
        self::assertSame('CanonicalUser', $acl['Grants'][0]['Grantee']['Type']);
        $this->storage->delete($reference);
        $this->storage->delete($reference);
        self::assertFalse($this->storage->exists($reference));
        $this->rejected(ObjectStorageError::OBJECT_NOT_FOUND, fn () => $this->storage->read($reference));
        $this->context->setTenant($this->b);
        self::assertSame('neighbor', $this->storage->read($b));
        $this->context->clear();
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->exists($b));
        $this->context->setTenant($this->a);
        self::assertSame($content, $this->storage->read($moved));
    }

    public function testDedicatedOverrideGenerationRoutingAndNeighborBucketNeverChanges(): void
    {
        $old = $this->storage->allocate();
        $this->storage->write($old, 'historical');
        $next = $this->facade('next')->allocate();
        $sameSuffix = new StoredObjectReference($next->locationId, $next->locationBinding, $next->tenantNamespace, $old->key);
        $this->storage->write($sameSuffix, 'other-bucket');
        $switched = new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'), $this->namespaces,
            new ObjectStorageRegistry([$this->registry->location('shared_v1'), $this->registry->location('shared_v2')], ['shared' => 'shared_v2']));
        self::assertSame('shared_v2', $switched->allocate()->locationId);
        self::assertSame('historical', $switched->read($old));
        self::assertSame('other-bucket', $this->storage->read($sameSuffix));
        $override = $this->facade('shared', ['1' => 'dedicated']);
        $dedicated = $override->allocate();
        self::assertSame('dedicated_v1', $dedicated->locationId);
        $override->write($dedicated, 'dedicated-content');
        foreach (['copy', 'move'] as $method) {
            $calls = $this->backends['shared_v1']->calls;
            $this->rejected(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->$method($old, $dedicated));
            self::assertSame($calls, $this->backends['shared_v1']->calls);
        }
        $this->rejected(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->temporaryUrl($dedicated));
        self::assertSame('historical', $this->storage->read($old));
        self::assertSame('dedicated-content', $this->storage->read($dedicated));
        $this->context->setTenant($this->b);
        self::assertSame('shared_v1', $override->allocate()->locationId);
    }

    public function testForeignReferencesAndSerializedReferencesAreRevalidatedForEveryPrimitive(): void
    {
        $a = $this->storage->allocate();
        $this->storage->write($a, 'A');
        $this->context->setTenant($this->b);
        $b = $this->storage->allocate();
        $this->storage->write($b, 'B');
        $serializer = new PhpSerializer();
        $restored = $serializer->decode($serializer->encode(new Envelope($b, [new TenantStamp('2')])))->getMessage();
        self::assertTrue($b->equals($restored));
        $this->context->setTenant($this->a);
        $calls = $this->backends['shared_v1']->calls;
        $stream = fopen('php://temp', 'w+b');
        try {
            foreach (['write', 'writeFromStream', 'read', 'readToStream', 'exists', 'metadata', 'list', 'copy', 'move', 'copyDestination', 'moveDestination', 'delete', 'temporaryUrl'] as $method) {
                $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => match ($method) {
                    'write' => $this->storage->write($restored, 'forbidden'),
                    'writeFromStream', 'readToStream' => $this->storage->$method($restored, $stream),
                    'copy', 'move' => $this->storage->$method($restored, $a),
                    'copyDestination' => $this->storage->copy($a, $restored),
                    'moveDestination' => $this->storage->move($a, $restored),
                    default => $this->storage->$method($restored),
                });
            }
        } finally {
            fclose($stream);
        }
        self::assertSame($calls, $this->backends['shared_v1']->calls, 'Every foreign-reference rejection occurs before real backend entry.');
        self::assertSame('A', $this->storage->read($a));
        $this->context->setTenant($this->b);
        self::assertSame('B', $this->storage->read($b));
    }

    public function testSignedHttpsDownloadAndServerEnforcedExpiry(): void
    {
        $ref = $this->storage->allocate();
        $this->storage->write($ref, 'private-download');
        $url = $this->storage->temporaryUrl($ref, 2);
        self::assertSame('https', parse_url($url->url, PHP_URL_SCHEME));
        self::assertSame('minio-public', parse_url($url->url, PHP_URL_HOST));
        $http = new Client(['verify' => getenv('MTB_OBJECT_CA'), 'http_errors' => false]);
        self::assertSame('private-download', (string) $http->get($url->url)->getBody());
        self::assertSame(403, $http->head($url->url)->getStatusCode(), 'The signature authorizes exactly GET, not HEAD.');
        foreach ([0, -1, 901] as $ttl) {
            $this->rejected(ObjectStorageError::INVALID_ARGUMENT, fn () => $this->storage->temporaryUrl($ref, $ttl));
        }
        $this->rejected(ObjectStorageError::INVALID_ARGUMENT, fn () => $this->backends['shared_v1']->temporaryUrl('objects/v1/'.$ref->tenantNamespace.'/'.$ref->key, new \DateTimeImmutable('-1 second')));
        parse_str(parse_url($url->url, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $name) {
            self::assertContains($name, ['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature', 'X-Amz-Content-Sha256']);
        }
        self::assertStringNotContainsString(self::SECRET, $url->url);
        self::assertStringNotContainsString(self::ACCESS, $ref->toJson());
        sleep(3);
        self::assertSame(403, $http->get($url->url)->getStatusCode());
    }

    public function testInvalidCredentialsEndpointAndMissingBucketAreExceptionsNeverAbsence(): void
    {
        $key = 'objects/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64);
        foreach ([
            S3CompatibleStorageFactory::create($this->config(), 'mtb-object-invalid', 'synthetic-invalid', caBundle: getenv('MTB_OBJECT_CA')),
            S3CompatibleStorageFactory::create(new S3LocationConfiguration('https://minio:1', $this->buckets['shared']), self::ACCESS, self::SECRET, caBundle: getenv('MTB_OBJECT_CA')),
            S3CompatibleStorageFactory::create(new S3LocationConfiguration(getenv('MTB_OBJECT_ENDPOINT'), 'mtb-object-nonexistent-'.bin2hex(random_bytes(8))), self::ACCESS, self::SECRET, caBundle: getenv('MTB_OBJECT_CA')),
        ] as $backend) {
            $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $backend->exists($key));
        }
    }

    public function testEffectiveBindingChangesAndUnknownSelectionsRefuseBeforeMinioIo(): void
    {
        $ref = $this->storage->allocate();
        $this->storage->write($ref, 'persisted');
        $original = $this->backends['shared_v1']->delegate;
        $calls = $this->backends['shared_v1']->calls;
        foreach ([new S3LocationConfiguration('https://minio:1', $this->buckets['shared'], 'technical'),
            $this->config('generation'), $this->config('shared', 'other-root'),
            new S3LocationConfiguration(getenv('MTB_OBJECT_ENDPOINT'), $this->buckets['shared'], 'technical', false)] as $config) {
            $this->backends['shared_v1']->delegate = S3CompatibleStorageFactory::create($config, self::ACCESS, self::SECRET, true, getenv('MTB_OBJECT_CA'));
            $this->rejected(ObjectStorageError::BINDING_MISMATCH, fn () => $this->storage->read($ref));
            $this->rejected(ObjectStorageError::BINDING_MISMATCH, fn () => $this->storage->temporaryUrl($ref));
            self::assertSame($calls, $this->backends['shared_v1']->calls);
        }
        $this->backends['shared_v1']->delegate = $original;
        $this->rejected(ObjectStorageError::UNKNOWN_PROVIDER, fn () => $this->facade('unknown')->allocate());
        $unknown = new StoredObjectReference('unknown_v1', $ref->locationBinding, $ref->tenantNamespace, $ref->key);
        $this->rejected(ObjectStorageError::UNKNOWN_LOCATION, fn () => $this->storage->read($unknown));
        self::assertSame($calls, $this->backends['shared_v1']->calls);
        self::assertSame('persisted', $this->storage->read($ref));
        $rotated = S3CompatibleStorageFactory::create($this->config(), 'mtb-object-rotated', 'mtb-object-rotated-synthetic-password', true, getenv('MTB_OBJECT_CA'));
        self::assertSame($ref->locationBinding, $rotated->identity($rotated)->fingerprint());
        $this->backends['shared_v1']->delegate = $rotated;
        self::assertSame('persisted', $this->storage->read($ref));
        $this->storage->write($ref, 'written-after-rotation');
        $this->backends['shared_v1']->delegate = $original;
        self::assertSame('written-after-rotation', $this->storage->read($ref));
    }

    public function testRealReadInterruptedByCallerAndRealUploadStartsAtCurrentPosition(): void
    {
        $ref = $this->storage->allocate();
        $source = fopen('php://temp', 'w+b');
        fwrite($source, 'skip:'.str_repeat('x', 180000));
        fseek($source, 5);
        try {
            $this->storage->writeFromStream($ref, $source);
            self::assertTrue(is_resource($source));
            self::assertSame(str_repeat('x', 180000), $this->storage->read($ref));
        } finally {
            fclose($source);
        }
        $class = \Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InterruptingStream::class;
        self::assertTrue(stream_wrapper_register('mtbminiotest', $class));
        $class::$written = '';
        $class::$eof = false;
        $class::$onIo = fn () => $this->context->setTenant($this->b);
        $destination = fopen('mtbminiotest://caller', 'w');
        try {
            $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $this->storage->readToStream($ref, $destination));
            self::assertTrue(is_resource($destination));
            self::assertLessThanOrEqual(65536, strlen($class::$written));
        } finally {
            fclose($destination);
            stream_wrapper_unregister('mtbminiotest');
            $class::$onIo = null;
        }
        $this->context->setTenant($this->a);
        self::assertSame(180000, $this->storage->metadata($ref)->size);
    }
}
