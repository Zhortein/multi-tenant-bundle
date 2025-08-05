<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * AWS S3 storage implementation with tenant isolation.
 *
 * This storage implementation creates tenant-specific prefixes/buckets
 * and handles file operations within those isolated spaces.
 *
 * Note: This is a basic implementation. For production use, consider
 * using the official AWS SDK or Flysystem with S3 adapter.
 */
final readonly class S3Storage implements TenantFileStorageInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private string                 $bucket, // @phpstan-ignore-line
        private string                 $region, // @phpstan-ignore-line
        private string                 $baseUrl,
        private ?string                $accessKey = null, // @phpstan-ignore-line
        private ?string                $secretKey = null, // @phpstan-ignore-line
    ) {
    }

    public function upload(File $file, string $path): string
    {
        $tenantPath = $this->getTenantPath($path);

        // This is a simplified implementation
        // In production, use AWS SDK or Flysystem
        $this->uploadToS3($file->getPathname(), $tenantPath);

        return $tenantPath;
    }

    public function uploadFile(UploadedFile $file, string $path): string
    {
        $tenantPath = $this->getTenantPath($path);

        // This is a simplified implementation
        // In production, use AWS SDK or Flysystem
        $this->uploadToS3($file->getPathname(), $tenantPath);

        return $tenantPath;
    }

    public function delete(string $path): void
    {
        $tenantPath = $this->getTenantPath($path);

        // This is a simplified implementation
        // In production, use AWS SDK or Flysystem
        $this->deleteFromS3($tenantPath);
    }

    public function exists(string $path): bool
    {
        $tenantPath = $this->getTenantPath($path);

        // This is a simplified implementation
        // In production, use AWS SDK or Flysystem
        return $this->existsInS3($tenantPath);
    }

    public function getUrl(string $path): string
    {
        $tenantPath = $this->getTenantPath($path);

        return rtrim($this->baseUrl, '/').'/'.ltrim($tenantPath, '/');
    }

    public function getPath(string $path): string
    {
        return $this->getTenantPath($path);
    }

    public function listFiles(string $directory = ''): array
    {
        $tenantDirectory = $this->getTenantPath($directory);

        // This is a simplified implementation
        // In production, use AWS SDK or Flysystem
        return $this->listFromS3($tenantDirectory);
    }

    /**
     * Gets the tenant-specific S3 path.
     */
    private function getTenantPath(string $path): string
    {
        $tenant = $this->tenantContext->getTenant();
        $tenantSlug = $tenant?->getSlug() ?? 'default';

        return $tenantSlug.'/'.ltrim($path, '/');
    }

    /**
     * Uploads a file to S3 (simplified implementation).
     *
     * @param string $localPath The local file path
     * @param string $s3Path    The S3 object key
     */
    private function uploadToS3(string $localPath, string $s3Path): void
    {
        // TODO: Implement actual S3 upload using AWS SDK
        // Example:
        // $this->s3Client->putObject([
        //     'Bucket' => $this->bucket,
        //     'Key' => $s3Path,
        //     'SourceFile' => $localPath,
        // ]);

        throw new \RuntimeException('S3 upload not implemented. Please use AWS SDK or Flysystem.');
    }

    /**
     * Deletes a file from S3 (simplified implementation).
     */
    private function deleteFromS3(string $s3Path): void
    {
        // TODO: Implement actual S3 delete using AWS SDK
        throw new \RuntimeException('S3 delete not implemented. Please use AWS SDK or Flysystem.');
    }

    /**
     * Checks if a file exists in S3 (simplified implementation).
     */
    private function existsInS3(string $s3Path): bool
    {
        // TODO: Implement actual S3 exists check using AWS SDK
        throw new \RuntimeException('S3 exists check not implemented. Please use AWS SDK or Flysystem.');
    }

    /**
     * Lists files from S3 (simplified implementation).
     *
     * @return array<string>
     */
    private function listFromS3(string $prefix): array
    {
        // TODO: Implement actual S3 list using AWS SDK
        throw new \RuntimeException('S3 list not implemented. Please use AWS SDK or Flysystem.');
    }
}
