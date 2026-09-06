<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\FlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\KeysetListingInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\OperationOutcome;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;

final class FlysystemBridgeTest extends TestCase
{
    private const KEY = 'objects/v1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function richFailures(): iterable
    {
        foreach (['flysystem', 's3', 'http', 'network'] as $failure) {
            foreach (['write', 'writeStream', 'read', 'readStream', 'fileExists', 'fileSize', 'lastModified', 'copy', 'move', 'delete', 'list'] as $operation) {
                yield $failure.' '.$operation => [$failure, $operation];
            }
        }
    }

    #[DataProvider('richFailures')]
    public function testVendorFailuresExposeNoCauseMessageOrDiagnostics(string $failure, string $operation): void
    {
        $sensitive = 'test-only-credential bucket/private-key https://backend.invalid/?X-Amz-Signature=synthetic';
        $request = new Request('GET', 'https://backend.invalid/bucket/private-key?X-Amz-Signature=synthetic');
        $error = match ($failure) {
            'flysystem' => UnableToWriteFile::atLocation($sensitive, $sensitive, new \RuntimeException($sensitive)),
            's3' => new S3Exception($sensitive, new Command('GetObject'), ['response' => new Response(503, [], $sensitive)]),
            'http' => RequestException::create($request, new Response(503, [], $sensitive)),
            'network' => new ConnectException($sensitive, $request),
        };
        $operator = $this->createStub(FilesystemOperator::class);
        $listing = $this->createStub(KeysetListingInterface::class);
        if ('fileExists' !== $operation) {
            $operator->method('fileExists')->willReturn(true);
        }
        if ('list' === $operation) {
            $listing->method('list')->willThrowException($error);
        } else {
            $operator->method($operation)->willThrowException($error);
        }
        $backend = new FlysystemBackend($operator, new PhysicalStorageIdentity('test', 'test', 'test'), $listing);
        $source = new class implements ObjectStreamSourceInterface {
            public function eof(): bool
            {
                return true;
            }

            public function readChunk(): string
            {
                return '';
            }
        };
        $destination = new class implements ObjectStreamDestinationInterface {
            public function writeChunk(string $chunk): void
            {
            }
        };
        ob_start();
        try {
            match ($operation) {
                'write' => $backend->write(self::KEY, 'payload'),
                'writeStream' => $backend->writeFromStream(self::KEY, $source),
                'readStream' => $backend->readToStream(self::KEY, $destination),
                'fileExists' => $backend->exists(self::KEY),
                'fileSize', 'lastModified' => $backend->metadata(self::KEY),
                'copy', 'move' => $backend->$operation(self::KEY, substr(self::KEY, 0, 76).str_repeat('c', 64)),
                'list' => $backend->list(substr(self::KEY, 0, 76), 1),
                default => $backend->$operation(self::KEY),
            };
            self::fail('A backend failure must throw.');
        } catch (ObjectStorageBackendException $e) {
            self::assertNull($e->getPrevious());
            self::assertSame('Object storage: backend_failure.', $e->getMessage());
            self::assertSame(OperationOutcome::UNKNOWN, $e->outcome);
            self::assertSame(['reason', 'outcome'], array_keys(get_object_vars($e)));
        } finally {
            self::assertSame('', ob_get_clean());
        }
    }

    public function testOwnedStreamsCloseOnFailureAndSpoolingFailureDoesNotWrite(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->expects(self::once())->method('fileExists')->willReturn(true);
        $read = fopen('php://temp', 'w+b');
        fwrite($read, str_repeat('x', 150000));
        rewind($read);
        $operator->expects(self::once())->method('readStream')->willReturn($read);
        $operator->expects(self::never())->method('writeStream');
        $backend = new FlysystemBackend($operator, new PhysicalStorageIdentity('test', 'test', 'test'), $this->createStub(KeysetListingInterface::class));
        $destination = new class implements ObjectStreamDestinationInterface {
            public int $chunks = 0;

            public function writeChunk(string $chunk): void
            {
                if (++$this->chunks > 1) {
                    throw new \RuntimeException('synthetic private diagnostic');
                }
                TestCase::assertLessThanOrEqual(65536, strlen($chunk));
            }
        };
        try {
            $backend->readToStream(self::KEY, $destination);
            self::fail('Destination interruption must throw.');
        } catch (ObjectStorageBackendException $e) {
            self::assertSame(OperationOutcome::PARTIAL, $e->outcome);
            self::assertFalse(is_resource($read));
            self::assertNull($e->getPrevious());
        }
        $source = new class implements ObjectStreamSourceInterface {
            public function eof(): bool
            {
                return false;
            }

            public function readChunk(): string
            {
                throw new \RuntimeException('synthetic private diagnostic');
            }
        };
        try {
            $backend->writeFromStream(self::KEY, $source);
            self::fail('Source interruption must throw.');
        } catch (ObjectStorageBackendException $e) {
            self::assertSame(OperationOutcome::NOT_APPLIED, $e->outcome);
            self::assertNull($e->getPrevious());
        }
    }

    public function testCanonicalIdentityAndCredentialRotation(): void
    {
        $one = new S3LocationConfiguration('HTTPS://MINIO:443/', 'test-bucket', '/technical/root/');
        $two = new S3LocationConfiguration('https://minio', 'test-bucket', 'technical/root');
        self::assertSame($one->identity()->fingerprint(), $two->identity()->fingerprint());
        foreach ([new S3LocationConfiguration('https://other', 'test-bucket', 'technical/root'),
            new S3LocationConfiguration('https://minio', 'other-bucket', 'technical/root'),
            new S3LocationConfiguration('https://minio', 'test-bucket', 'other'),
            new S3LocationConfiguration('https://minio', 'test-bucket', 'technical/root', false),
            new S3LocationConfiguration('https://minio', 'test-bucket', 'technical/root', signingEndpoint: 'https://public')] as $changed) {
            self::assertNotSame($one->identity()->fingerprint(), $changed->identity()->fingerprint());
        }
        $first = S3CompatibleStorageFactory::create($one, 'test-first', 'synthetic-first');
        $rotated = S3CompatibleStorageFactory::create($two, 'test-rotated', 'synthetic-rotated');
        self::assertSame($first->identity($first)->fingerprint(), $rotated->identity($rotated)->fingerprint());
    }

    public function testOperatorMayCloseTheOwnedSpoolWithoutAFalseUploadFailure(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->expects(self::once())->method('writeStream')->willReturnCallback(static function (string $key, mixed $stream): void {
            self::assertTrue(is_resource($stream));
            // Some SDK/HTTP combinations close an uploaded stream themselves.
            fclose($stream);
        });
        $backend = new FlysystemBackend($operator, new PhysicalStorageIdentity('test', 'test', 'test'), $this->createStub(KeysetListingInterface::class));
        $source = new class implements ObjectStreamSourceInterface {
            public function readChunk(): string
            {
                return '';
            }

            public function eof(): bool
            {
                return true;
            }
        };
        $backend->writeFromStream(self::KEY, $source);
    }

    public static function invalidTargets(): iterable
    {
        foreach (['http://minio', 'https://user:pass@minio', 'https://minio/path', 'https://minio/?token=value', 'https://minio/#fragment', "https://minio\n", 'https://minio%2fneighbor'] as $endpoint) {
            yield [$endpoint];
        }
    }

    public function testOwnedStreamCloseFailureIsSanitized(): void
    {
        stream_wrapper_register('mtbfailingclose', FailingCloseStream::class);
        try {
            $stream = fopen('mtbfailingclose://test', 'rb');
            $operator = $this->createStub(FilesystemOperator::class);
            $operator->method('fileExists')->willReturn(true);
            $operator->method('readStream')->willReturn($stream);
            $backend = new FlysystemBackend($operator, new PhysicalStorageIdentity('test', 'test', 'test'), $this->createStub(KeysetListingInterface::class));
            $destination = $this->createStub(ObjectStreamDestinationInterface::class);
            try {
                $backend->readToStream(self::KEY, $destination);
                self::fail('Closing an owned stream can fail.');
            } catch (ObjectStorageBackendException $e) {
                self::assertNull($e->getPrevious());
                self::assertSame('Object storage: backend_failure.', $e->getMessage());
            }
        } finally {
            stream_wrapper_unregister('mtbfailingclose');
        }
    }

    #[DataProvider('invalidTargets')]
    public function testAmbiguousOrSensitiveEndpointRejected(string $endpoint): void
    {
        $this->expectException(ObjectStorageException::class);
        new S3LocationConfiguration($endpoint, 'test-bucket');
    }
}

/** Test-only PHP wrapper whose close callback fails with private diagnostics. */
final class FailingCloseStream
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        throw new \RuntimeException('synthetic private endpoint bucket credential');
    }
}
