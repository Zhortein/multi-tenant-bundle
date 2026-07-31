<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Local filesystem storage implementation with fail-closed tenant isolation.
 */
final readonly class LocalStorage implements TenantFileStorageInterface
{
    private Filesystem $fs;
    private TenantStoragePathResolver $pathResolver;

    public function __construct(
        TenantContextInterface $tenantContext,
        private string $basePath,
        private string $baseUrl = '',
    ) {
        $this->fs = new Filesystem();
        $this->pathResolver = new TenantStoragePathResolver($tenantContext);
    }

    public function upload(File $file, string $path): string
    {
        $tenantPath = $this->pathResolver->resolve($path);
        $targetPath = $this->absolutePath($tenantPath);

        $this->fs->mkdir(dirname($targetPath));
        $this->assertNoSymlinkEscape($targetPath);
        $this->fs->copy($file->getPathname(), $targetPath, true);

        return $tenantPath;
    }

    public function uploadFile(UploadedFile $file, string $path): string
    {
        $tenantPath = $this->pathResolver->resolve($path);
        $targetPath = $this->absolutePath($tenantPath);

        $this->fs->mkdir(dirname($targetPath));
        $this->assertNoSymlinkEscape($targetPath);
        $file->move(dirname($targetPath), basename($targetPath));

        return $tenantPath;
    }

    public function delete(string $path): void
    {
        $this->fs->remove($this->getPath($path));
    }

    public function exists(string $path): bool
    {
        return file_exists($this->getPath($path));
    }

    public function getUrl(string $path): string
    {
        $tenantPath = $this->pathResolver->resolve($path);

        return rtrim($this->baseUrl, '/').'/'.$tenantPath;
    }

    public function getPath(string $path): string
    {
        return $this->absolutePath($this->pathResolver->resolve($path));
    }

    public function listFiles(string $directory = ''): array
    {
        $relativeDirectory = $this->pathResolver->validateRelativePath($directory, true);
        $tenantDirectory = $this->pathResolver->tenantNamespace();
        $fullDirectory = $this->absolutePath(
            '' === $relativeDirectory ? $tenantDirectory : $tenantDirectory.'/'.$relativeDirectory
        );

        if (!is_dir($fullDirectory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isLink()) {
                throw new TenantStorageException('Symbolic links are not allowed inside tenant storage.');
            }

            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($fullDirectory) + 1);
                $files[] = '' === $relativeDirectory ? $relativePath : $relativeDirectory.'/'.$relativePath;
            }
        }

        return $files;
    }

    private function absolutePath(string $tenantPath): string
    {
        $basePath = rtrim($this->basePath, '/\\');
        if ('' === $basePath) {
            throw new TenantStorageException('The local storage base path must not be empty.');
        }

        $targetPath = $basePath.'/'.$tenantPath;
        $this->assertNoSymlinkEscape($targetPath);

        return $targetPath;
    }

    private function assertNoSymlinkEscape(string $targetPath): void
    {
        $basePath = rtrim($this->basePath, '/\\');

        $this->assertNoSymlinkComponents(
            $basePath,
            'The local storage base path must not contain symbolic links.'
        );

        $relativePath = substr($targetPath, strlen($basePath) + 1);
        $currentPath = $basePath;

        foreach (explode('/', $relativePath) as $component) {
            $currentPath .= '/'.$component;

            if (is_link($currentPath)) {
                throw new TenantStorageException('Symbolic links are not allowed inside tenant storage.');
            }
        }
    }

    private function assertNoSymlinkComponents(string $path, string $message): void
    {
        $normalized = str_replace('\\', '/', $path);
        $currentPath = str_starts_with($normalized, '/') ? '/' : '';

        foreach (array_filter(explode('/', $normalized), static fn (string $component): bool => '' !== $component) as $component) {
            $currentPath = '/' === $currentPath ? $currentPath.$component : $currentPath.'/'.$component;
            if (is_link($currentPath)) {
                throw new TenantStorageException($message);
            }
        }
    }
}
