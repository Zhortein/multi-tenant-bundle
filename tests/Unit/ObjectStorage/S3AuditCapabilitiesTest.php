<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use Aws\Command;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3Capabilities;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

final class S3AuditCapabilitiesTest extends TestCase
{
    public function testMalformedGlobalResponsesFailClosedWhileInvalidScopedKeysCanResume(): void
    {
        $prefix = 'objects/v1/'.str_repeat('a', 64).'/';
        $key = $prefix.'invalid-name';
        foreach ([[], ['IsTruncated' => 'false'], ['IsTruncated' => true], ['IsTruncated' => false, 'Contents' => 'bad'],
            ['IsTruncated' => false, 'Contents' => [['missing' => 'key']]],
            ['IsTruncated' => false, 'Contents' => [2 => ['Key' => $key]]],
            ['IsTruncated' => false, 'Contents' => [['Key' => 'foreign/'.$key]]],
            ['IsTruncated' => false, 'Contents' => [['Key' => $key], ['Key' => $key]]],
            ['IsTruncated' => false, 'Contents' => [], 'Prefix' => 'foreign'],
            ['IsTruncated' => false, 'Contents' => [], 'KeyCount' => 1],
        ] as $response) {
            $client = $this->createStub(S3ClientInterface::class);
            $client->method('getCommand')->willReturn(new Command('ListObjectsV2'));
            $client->method('execute')->willReturn(new Result($response));
            $capability = new S3Capabilities($client, $client, new S3LocationConfiguration('https://minio', 'test-bucket'));
            try {
                $capability->auditList($prefix, 2);
                self::fail('Malformed page.');
            } catch (ObjectStorageException $e) {
                self::assertSame('Object storage: backend_failure.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
        $client = $this->createMock(S3ClientInterface::class);
        $client->expects(self::once())->method('getCommand')->with('ListObjectsV2', ['Bucket' => 'test-bucket', 'Prefix' => $prefix, 'MaxKeys' => 1, 'StartAfter' => $key])->willReturn(new Command('ListObjectsV2'));
        $client->expects(self::once())->method('execute')->willReturn(new Result(['IsTruncated' => false, 'Contents' => [['Key' => $prefix.'z-invalid']]]));
        $capability = new S3Capabilities($client, $client, new S3LocationConfiguration('https://minio', 'test-bucket'));
        self::assertSame([$prefix.'z-invalid'], $capability->auditList($prefix, 1, $key)->keys);
    }

    public function testHeadMetadataIsObservedWithoutDownloadingContent(): void
    {
        foreach ([[[], null], [[AuditableFlysystemBackend::IDENTITY_METADATA => 'envelope'], 'envelope'], [['wrong' => 'other'], null], [[AuditableFlysystemBackend::IDENTITY_METADATA => []], ''], ['broken', '']] as [$metadata, $expected]) {
            $key = 'objects/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64);
            $client = $this->createMock(S3ClientInterface::class);
            $client->expects(self::once())->method('getCommand')->with('HeadObject', ['Bucket' => 'test-bucket', 'Key' => $key])->willReturn(new Command('HeadObject'));
            $client->expects(self::once())->method('execute')->willReturn(new Result(['ContentLength' => 7, 'Metadata' => $metadata]));
            $capability = new S3Capabilities($client, $client, new S3LocationConfiguration('https://minio', 'test-bucket'));
            $result = $capability->observeIdentity($key);
            self::assertSame($expected, $result->envelope);
            self::assertSame(7, $result->metadata->size);
        }
    }
}
