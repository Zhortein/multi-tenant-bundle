<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Local filesystem storage implementation with tenant isolation.
 *
 * This storage implementation creates tenant-specific directories
 * and handles file operations within those isolated spaces.
 */
final class LocalStorage implements TenantFileStorageInterface
{
    private readonly Filesystem $fs;

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly string $basePath,
        private readonly string $baseUrl = '',
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * {@inheritdoc}
     */
    public function upload(File $file, string $path): string
    {
        $targetPath = $this->getPath($path);

        $this->fs->mkdir(dirname($targetPath));
        $this->fs->copy($file->getPathname(), $targetPath, true);

        return $this->getTenantRelativePath($path);
    }

    /**
     * {@inheritdoc}
     */
    public function uploadFile(UploadedFile $file, string $path): string
    {
        $targetPath = $this->getPath($path);

        $this->fs->mkdir(dirname($targetPath));
        $file->move(dirname($targetPath), basename($targetPath));

        return $this->getTenantRelativePath($path);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        $targetPath = $this->getPath($path);
        $this->fs->remove($targetPath);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return file_exists($this->getPath($path));
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(string $path): string
    {
        $tenantPath = $this->getTenantRelativePath($path);
        return rtrim($this->baseUrl, '/').'/'.ltrim($tenantPath, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(string $path): string
    {
        $tenantPath = $this->getTenantRelativePath($path);
        return rtrim($this->basePath, '/').'/'.ltrim($tenantPath, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function listFiles(string $directory = ''): array
    {
        $fullDirectory = $this->getPath($directory);
        
        if (!is_dir($fullDirectory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $pathname = $file->getPathname();
                $relativePath = str_replace($fullDirectory.'/', '', is_string($pathname) ? $pathname : '');
                $files[] = $directory ? $directory.'/'.$relativePath : $relativePath;
            }
        }

        return $files;
    }

    /**
     * Gets the tenant-specific relative path.
     */
    private function getTenantRelativePath(string $path): string
    {
        $tenant = $this->tenantContext->getTenant();
        $tenantSlug = $tenant?->getSlug() ?? 'default';
        
        return $tenantSlug.'/'.ltrim($path, '/');
    }
}
