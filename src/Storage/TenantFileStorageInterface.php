<?php

namespace Zhortein\MultiTenantBundle\Storage;

use Symfony\Component\HttpFoundation\File\File;

interface TenantFileStorageInterface
{
    public function upload(File $file, string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function getUrl(string $path): string;
}