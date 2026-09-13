<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageBackendException;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\OperationOutcome;

/** S3 is a protocol here. An explicit HTTPS endpoint is always required. */
final class S3CompatibleStorageFactory
{
    public static function create(
        S3LocationConfiguration $config,
        #[\SensitiveParameter] string $accessKey,
        #[\SensitiveParameter] string $secretKey,
        bool $temporaryUrls = false,
        ?string $caBundle = null,
        bool $audit = false,
    ): FlysystemBackend {
        if (!class_exists(S3Client::class) || !class_exists(AwsS3V3Adapter::class) || !class_exists(Filesystem::class)) {
            throw new \LogicException('object_storage S3 bridge requires league/flysystem:^3.30.2, league/flysystem-aws-s3-v3:^3.30.1 and aws/aws-sdk-php:^3.371.5. Install them with Composer.');
        }
        try {
            $options = [
                'version' => '2006-03-01', 'endpoint' => $config->endpoint, 'region' => $config->region,
                'use_path_style_endpoint' => $config->pathStyle, 'signature_version' => 'v4',
                'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
                'retries' => 0, 'http' => ['verify' => $caBundle ?? true, 'connect_timeout' => 5, 'timeout' => 30],
            ];
            $client = new S3Client($options);
            $signer = $config->endpoint === $config->signingEndpoint ? $client : new S3Client(array_replace($options, ['endpoint' => $config->signingEndpoint]));
            $adapter = new AwsS3V3Adapter($client, $config->bucket, $config->root, streamReads: true);
            $filesystem = new Filesystem($adapter);
            $capabilities = new S3Capabilities($client, $signer, $config);

            if ($audit) {
                return new AuditableFlysystemBackend($filesystem, $config->identity(), $capabilities, $capabilities, $capabilities, $capabilities, $temporaryUrls ? $capabilities : null);
            }

            return $temporaryUrls
                ? new SigningFlysystemBackend($filesystem, $config->identity(), $capabilities, $capabilities, $capabilities)
                : new FlysystemBackend($filesystem, $config->identity(), $capabilities, $capabilities);
        } catch (\Throwable) {
            throw new ObjectStorageBackendException(OperationOutcome::NOT_APPLIED);
        }
    }
}
