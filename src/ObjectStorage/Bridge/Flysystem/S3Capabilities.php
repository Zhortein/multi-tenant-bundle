<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Aws\S3\S3ClientInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** @internal Constructed alongside the operator; uses only public SDK APIs. */
final readonly class S3Capabilities implements KeysetListingInterface, ExistenceCheckerInterface, TemporaryObjectUrlBackendInterface
{
    public function __construct(private S3ClientInterface $client, private S3ClientInterface $signer, private S3LocationConfiguration $config)
    {
    }

    public function exists(string $qualifiedKey): bool
    {
        QualifiedKey::validate($qualifiedKey);
        try {
            // HeadObject loses NoSuchBucket versus NoSuchKey on some servers.
            // A successful bounded listing proves the bucket is accessible.
            $key = $this->config->prefix($qualifiedKey);
            $result = $this->client->execute($this->client->getCommand('ListObjectsV2', [
                'Bucket' => $this->config->bucket, 'Prefix' => $key, 'MaxKeys' => 1,
            ]));
            $contents = $result->get('Contents') ?? [];
            if (!is_array($contents) || count($contents) > 1) {
                throw new \RuntimeException();
            }

            return isset($contents[0]) && is_array($contents[0]) && ($contents[0]['Key'] ?? null) === $key;
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        QualifiedKey::page($tenantPrefix, $limit, $afterKey);
        try {
            $options = ['Bucket' => $this->config->bucket, 'Prefix' => $this->config->prefix($tenantPrefix), 'MaxKeys' => $limit];
            if (null !== $afterKey) {
                $options['StartAfter'] = $this->config->prefix($afterKey);
            }
            // Exactly one bounded request; no SDK paginator, no full-prefix scan.
            $result = $this->client->execute($this->client->getCommand('ListObjectsV2', $options));
            $contents = $result->get('Contents') ?? [];
            $more = $result->get('IsTruncated');
            if (!is_array($contents) || !is_bool($more) || count($contents) > $limit) {
                throw new \RuntimeException();
            }
            $keys = [];
            $previous = $afterKey;
            foreach ($contents as $item) {
                if (!is_array($item) || !is_string($item['Key'] ?? null)
                    || !str_starts_with($item['Key'], $this->config->prefix($tenantPrefix))) {
                    throw new \RuntimeException();
                }
                // Strip only the fixed technical adapter root, never the tenant prefix.
                $key = substr($item['Key'], strlen($this->config->prefix('')));
                QualifiedKey::validate($key);
                if (null !== $previous && strcmp($previous, $key) >= 0) {
                    throw new \RuntimeException();
                }
                $keys[] = $key;
                $previous = $key;
            }
            if ($more && [] === $keys) {
                throw new \RuntimeException();
            }

            return new BackendObjectPage($keys, $more);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        QualifiedKey::validate($qualifiedKey);
        $now = new \DateTimeImmutable();
        if ($expiresAt <= $now || $expiresAt > $now->modify('+'.$this->config->maxTtl.' seconds')) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        try {
            $command = $this->signer->getCommand('GetObject', ['Bucket' => $this->config->bucket, 'Key' => $this->config->prefix($qualifiedKey)]);
            $request = $this->signer->createPresignedRequest($command, $expiresAt);
            if ('GET' !== $request->getMethod()) {
                throw new \RuntimeException();
            }

            return new TemporaryObjectUrl((string) $request->getUri(), $expiresAt);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }
}
