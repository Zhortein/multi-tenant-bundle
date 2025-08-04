<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Interface for tenant-aware file storage operations.
 *
 * Implementations should handle tenant-specific file paths and storage
 * configurations, ensuring proper isolation between tenants.
 */
interface TenantFileStorageInterface
{
    /**
     * Uploads a file to tenant-specific storage.
     *
     * @param File   $file The file to upload
     * @param string $path The relative path within tenant storage
     *
     * @return string The final storage path
     */
    public function upload(File $file, string $path): string;

    /**
     * Uploads an uploaded file to tenant-specific storage.
     *
     * @param UploadedFile $file The uploaded file
     * @param string       $path The relative path within tenant storage
     *
     * @return string The final storage path
     */
    public function uploadFile(UploadedFile $file, string $path): string;

    /**
     * Deletes a file from tenant-specific storage.
     *
     * @param string $path The file path to delete
     */
    public function delete(string $path): void;

    /**
     * Checks if a file exists in tenant-specific storage.
     *
     * @param string $path The file path to check
     *
     * @return bool True if the file exists, false otherwise
     */
    public function exists(string $path): bool;

    /**
     * Gets the public URL for a file in tenant-specific storage.
     *
     * @param string $path The file path
     *
     * @return string The public URL
     */
    public function getUrl(string $path): string;

    /**
     * Gets the full file path for a tenant-specific file.
     *
     * @param string $path The relative file path
     *
     * @return string The full file path
     */
    public function getPath(string $path): string;

    /**
     * Lists files in a tenant-specific directory.
     *
     * @param string $directory The directory to list
     *
     * @return array<string> Array of file paths
     */
    public function listFiles(string $directory = ''): array;
}
