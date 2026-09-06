<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\ObjectStorage\Minio;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\FlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;

abstract class MinioTestCase extends TestCase
{
    protected const ACCESS = 'mtb-object-test-only';
    protected const SECRET = 'mtb-object-synthetic-disposable-password';
    protected S3Client $client;
    protected array $buckets = [];

    protected function setUp(): void
    {
        self::assertNotFalse(getenv('MTB_OBJECT_ENDPOINT'), 'Real MinIO is mandatory; run tests/ObjectStorage/run-minio.sh.');
        $this->client = new S3Client(['version' => '2006-03-01', 'region' => 'us-east-1',
            'endpoint' => getenv('MTB_OBJECT_ENDPOINT'), 'use_path_style_endpoint' => true,
            'credentials' => ['key' => self::ACCESS, 'secret' => self::SECRET],
            'retries' => 0, 'http' => ['verify' => getenv('MTB_OBJECT_CA'), 'timeout' => 10],
        ]);
        foreach (['shared', 'dedicated', 'generation'] as $name) {
            $bucket = 'mtb-object-'.bin2hex(random_bytes(8)).'-'.$name;
            $this->client->createBucket(['Bucket' => $bucket]);
            $this->buckets[$name] = $bucket;
        }
    }

    protected function config(string $name = 'shared', string $root = 'technical'): S3LocationConfiguration
    {
        return new S3LocationConfiguration(getenv('MTB_OBJECT_ENDPOINT'), $this->buckets[$name], $root,
            signingEndpoint: getenv('MTB_OBJECT_SIGNING_ENDPOINT'));
    }

    protected function backend(string $name = 'shared', bool $signing = true): FlysystemBackend
    {
        return S3CompatibleStorageFactory::create($this->config($name), self::ACCESS, self::SECRET, $signing, getenv('MTB_OBJECT_CA'));
    }

    protected function tearDown(): void
    {
        foreach ($this->buckets as $bucket) {
            foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                }
            }
            $this->client->deleteBucket(['Bucket' => $bucket]);
        }
    }
}
