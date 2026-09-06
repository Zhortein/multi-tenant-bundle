<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3Capabilities;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;

final class S3CapabilitiesTest extends TestCase
{
    public function testListingUsesExactlyOneBoundedCommandWithinTheSameBucketAndTenantPrefix(): void
    {
        $client = $this->createMock(S3ClientInterface::class);
        $config = new S3LocationConfiguration('https://minio', 'test-bucket', 'technical/root');
        $prefix = 'objects/v1/'.str_repeat('a', 64).'/';
        $after = $prefix.str_repeat('b', 64);
        $key = $prefix.str_repeat('c', 64);
        $command = new Command('ListObjectsV2');
        $client->expects(self::once())->method('getCommand')->with('ListObjectsV2', [
            'Bucket' => 'test-bucket', 'Prefix' => 'technical/root/'.$prefix, 'MaxKeys' => 1, 'StartAfter' => 'technical/root/'.$after,
        ])->willReturn($command);
        $client->expects(self::once())->method('execute')->with($command)->willReturn(new Result([
            'Contents' => [['Key' => 'technical/root/'.$key]], 'IsTruncated' => true,
        ]));
        $page = (new S3Capabilities($client, $client, $config))->list($prefix, 1, $after);
        self::assertSame([$key], $page->keys);
        self::assertTrue($page->hasMore);
    }

    public function testSdkFailuresInListingExistenceAndSigningNeverExposeTheirCause(): void
    {
        foreach (['list', 'exists', 'temporaryUrl'] as $operation) {
            $client = $this->createStub(S3ClientInterface::class);
            $client->method('getCommand')->willThrowException(new S3Exception(
                'synthetic endpoint bucket object-key access-key signed-url HTTP details', new Command('GetObject'),
                ['response' => new Response(503, ['x-amz-request-id' => 'sensitive'], 'synthetic-sensitive-response')],
            ));
            $capability = new S3Capabilities($client, $client, new S3LocationConfiguration('https://minio', 'test-bucket'));
            $prefix = 'objects/v1/'.str_repeat('a', 64).'/';
            try {
                match ($operation) {
                    'list' => $capability->list($prefix, 1),
                    'exists' => $capability->exists($prefix.str_repeat('b', 64)),
                    'temporaryUrl' => $capability->temporaryUrl($prefix.str_repeat('b', 64), new \DateTimeImmutable('+30 seconds')),
                };
                self::fail('SDK failure must propagate as a sanitized error.');
            } catch (ObjectStorageBackendException $e) {
                self::assertSame('Object storage: backend_failure.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    public function testMalformedExistenceResponsesNeverBecomeAbsence(): void
    {
        foreach ([[], ['IsTruncated' => 'false'], ['IsTruncated' => true],
            ['IsTruncated' => false, 'Contents' => [['Key' => 'neighbor/private-key']]],
            ['IsTruncated' => false, 'Contents' => ['invalid-item']],
            ['IsTruncated' => false, 'Contents' => [5 => ['Key' => 'unexpected']]],
        ] as $response) {
            $client = $this->createStub(S3ClientInterface::class);
            $client->method('getCommand')->willReturn(new Command('ListObjectsV2'));
            $client->method('execute')->willReturn(new Result($response));
            $capability = new S3Capabilities($client, $client, new S3LocationConfiguration('https://minio', 'test-bucket'));
            try {
                $capability->exists('objects/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64));
                self::fail('An invalid S3 response must not become an absent object.');
            } catch (ObjectStorageBackendException $e) {
                self::assertSame('Object storage: backend_failure.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }
}
