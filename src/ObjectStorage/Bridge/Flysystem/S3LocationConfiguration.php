<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;

/** Immutable, non-secret input shared by the client, adapter, signer and binding. */
final readonly class S3LocationConfiguration
{
    public string $endpoint;
    public string $signingEndpoint;
    public string $root;

    public function __construct(
        string $endpoint,
        public string $bucket,
        string $root = '',
        public bool $pathStyle = true,
        public string $region = 'us-east-1',
        ?string $signingEndpoint = null,
        public int $maxTtl = 900,
    ) {
        $this->endpoint = self::endpoint($endpoint);
        $this->signingEndpoint = self::endpoint($signingEndpoint ?? $endpoint);
        $this->root = trim($root, '/');
        if (1 !== preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/D', $bucket)
            || str_contains($bucket, '..') || false !== filter_var($bucket, FILTER_VALIDATE_IP)
            || ('' !== $this->root && 1 !== preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/D', $this->root))
            || 1 !== preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D', $region)
            || $maxTtl < 1 || $maxTtl > 86400) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    private static function endpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '')
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || 1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/D', $parts['host'])
            || preg_match('/[\x00-\x20\x7f]/', $endpoint)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }

        return 'https://'.strtolower($parts['host']).(443 === $port ? '' : ':'.$port);
    }

    public function identity(): PhysicalStorageIdentity
    {
        return new PhysicalStorageIdentity('flysystem-s3-v3', $this->endpoint, $this->bucket, [
            'format' => '1', 'root' => $this->root, 'path_style' => $this->pathStyle ? 'true' : 'false',
            'signing_endpoint' => $this->signingEndpoint, 'signing_region' => $this->region,
        ]);
    }

    public function prefix(string $qualifiedKey): string
    {
        return ('' === $this->root ? '' : $this->root.'/').$qualifiedKey;
    }
}
