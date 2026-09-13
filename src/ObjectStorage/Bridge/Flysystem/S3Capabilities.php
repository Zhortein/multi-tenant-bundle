<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\AuditListingBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendIdentityObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityObserverInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** @internal Constructed alongside the operator; uses only public SDK APIs. */
final readonly class S3Capabilities implements KeysetListingInterface, ExistenceCheckerInterface, TemporaryObjectUrlBackendInterface, AuditListingBackendInterface, ObjectIdentityObserverInterface
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
            $more = $result->get('IsTruncated');
            if (!is_bool($more) || !is_array($contents) || !array_is_list($contents)
                || count($contents) > 1 || ($more && [] === $contents)) {
                throw new \RuntimeException();
            }
            if ([] === $contents) {
                return false;
            }
            $item = $contents[0];
            if (!is_array($item) || !is_string($item['Key'] ?? null) || !str_starts_with($item['Key'], $key)) {
                throw new \RuntimeException();
            }

            return $item['Key'] === $key;
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

    public function auditList(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        QualifiedKey::validate($tenantPrefix, true);
        if ($limit < 1 || $limit > 1000 || (null !== $afterKey && (!str_starts_with($afterKey, $tenantPrefix) || strlen($afterKey) > 1024))) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        try {
            $prefix = $this->config->prefix($tenantPrefix);
            $options = ['Bucket' => $this->config->bucket, 'Prefix' => $prefix, 'MaxKeys' => $limit];
            if (null !== $afterKey) {
                $options['StartAfter'] = $this->config->prefix($afterKey);
            }
            $result = $this->client->execute($this->client->getCommand('ListObjectsV2', $options));
            $contents = $result->get('Contents') ?? [];
            $more = $result->get('IsTruncated');
            if (!is_bool($more) || !is_array($contents) || !array_is_list($contents) || count($contents) > $limit
                || ($more && [] === $contents) || (null !== $result->get('Prefix') && $prefix !== $result->get('Prefix'))
                || (null !== $result->get('KeyCount') && count($contents) !== $result->get('KeyCount'))
                || (null !== $result->get('Name') && $this->config->bucket !== $result->get('Name'))) {
                throw new \RuntimeException();
            }
            $keys = [];
            $previous = $afterKey;
            foreach ($contents as $item) {
                if (!is_array($item) || !is_string($item['Key'] ?? null) || !str_starts_with($item['Key'], $prefix)) {
                    throw new \RuntimeException();
                }
                $key = substr($item['Key'], strlen($this->config->prefix('')));
                if (strlen($key) > 1024 || 1 !== preg_match('//u', $key) || (null !== $previous && strcmp($key, $previous) <= 0)) {
                    throw new \RuntimeException();
                }
                $keys[] = $key;
                $previous = $key;
            }

            return new BackendObjectPage($keys, $more);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException();
        }
    }

    public function observeIdentity(string $qualifiedKey): BackendIdentityObservation
    {
        QualifiedKey::validate($qualifiedKey);
        try {
            // One HEAD, no content download and no second request to another location.
            $result = $this->client->execute($this->client->getCommand('HeadObject', [
                'Bucket' => $this->config->bucket, 'Key' => $this->config->prefix($qualifiedKey),
            ]));
            $size = $result->get('ContentLength');
            if (!is_int($size) || $size < 0) {
                throw new \RuntimeException();
            }
            $modified = $result->get('LastModified');
            if (null !== $modified && !$modified instanceof \DateTimeInterface) {
                throw new \RuntimeException();
            }
            $objectMetadata = new ObjectMetadata($size, null === $modified ? null : \DateTimeImmutable::createFromInterface($modified));
            $metadata = $result->get('Metadata') ?? [];
            if (!is_array($metadata)) {
                return new BackendIdentityObservation($objectMetadata, '');
            }
            $envelope = array_key_exists(AuditableFlysystemBackend::IDENTITY_METADATA, $metadata) ? $metadata[AuditableFlysystemBackend::IDENTITY_METADATA] : null;
            if (array_key_exists(AuditableFlysystemBackend::IDENTITY_METADATA, $metadata) && !is_string($envelope)) {
                $envelope = '';
            }

            return new BackendIdentityObservation($objectMetadata, null === $envelope || is_string($envelope) ? $envelope : '');
        } catch (S3Exception $exception) {
            // Generic 404 may mean a missing bucket or denied access. Only explicit NoSuchKey is absence.
            if ('NoSuchKey' === $exception->getAwsErrorCode()) {
                throw new ObjectStorageException(ObjectStorageError::OBJECT_NOT_FOUND);
            }
            throw new ObjectStorageBackendException();
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
