<?php

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

final class LocalStorage implements TenantFileStorageInterface
{
    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = ''
    ) {
        $this->fs = new Filesystem();
    }

    public function upload(File $file, string $path): string
    {
        $targetPath = rtrim($this->basePath, '/') . '/' . ltrim($path, '/');

        $this->fs->mkdir(dirname($targetPath));
        $this->fs->copy($file->getPathname(), $targetPath, true);

        return $path;
    }

    public function delete(string $path): void
    {
        $targetPath = rtrim($this->basePath, '/') . '/' . ltrim($path, '/');
        $this->fs->remove($targetPath);
    }

    public function exists(string $path): bool
    {
        return file_exists(rtrim($this->basePath, '/') . '/' . ltrim($path, '/'));
    }

    public function getUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}