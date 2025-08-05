<?php

namespace Zhortein\MultiTenantBundle\Helper;

use Symfony\Component\HttpFoundation\File\File;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

final readonly class TenantAssetUploader
{
    public function __construct(
        private TenantFileStorageInterface $storage,
    ) {
    }

    /**
     * Upload a file for the current tenant.
     *
     * @param File        $file      The file to upload
     * @param string|null $directory Optional directory (e.g. "logos", "documents")
     *
     * @return string Relative path of the uploaded file
     */
    public function upload(File $file, ?string $directory = null): string
    {
        $filename = uniqid('', true).'_'.preg_replace('/[^a-zA-Z0-9_.-]/', '_', $file->getFilename());
        $path = ($directory ? trim($directory, '/').'/' : '').$filename;

        return $this->storage->upload($file, $path);
    }
}
