# Tenant-Aware Storage

The tenant-aware storage system provides isolated file storage for each tenant, supporting both local filesystem and cloud storage (S3-compatible) backends. Each tenant's files are completely isolated from other tenants while maintaining a unified API.

## Overview

The storage system provides:

- **Complete file isolation**: Each tenant has its own storage space
- **Multiple backends**: Local filesystem and S3-compatible storage
- **Unified API**: Same interface regardless of storage backend
- **Automatic path management**: Tenant-specific paths are handled automatically
- **URL generation**: Generate public URLs for tenant files
- **File operations**: Upload, download, delete, and list files

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: 'local' # 'local' or 's3'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
        s3:
            bucket: 'my-tenant-bucket'
            region: 'us-east-1'
            access_key: '%env(AWS_ACCESS_KEY_ID)%'
            secret_key: '%env(AWS_SECRET_ACCESS_KEY)%'
```

### Local Storage Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    storage:
        type: 'local'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
```

### S3 Storage Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    storage:
        type: 's3'
        s3:
            bucket: 'my-app-tenant-files'
            region: 'us-east-1'
            access_key: '%env(AWS_ACCESS_KEY_ID)%'
            secret_key: '%env(AWS_SECRET_ACCESS_KEY)%'
            endpoint: null # Optional: for S3-compatible services
```

## Basic Usage

### Injecting the Storage Service

```php
<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DocumentService
{
    public function __construct(
        private TenantFileStorageInterface $storage,
        private TenantContextInterface $tenantContext,
    ) {}

    public function uploadDocument(UploadedFile $file, string $category = 'documents'): string
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Generate unique filename
        $filename = $this->generateUniqueFilename($file);
        $path = sprintf('%s/%s', $category, $filename);

        // Upload file - automatically stored in tenant-specific directory
        $storedPath = $this->storage->uploadFile($file, $path);

        return $storedPath;
    }

    public function downloadDocument(string $path): ?string
    {
        if (!$this->storage->fileExists($path)) {
            return null;
        }

        return $this->storage->getFileContents($path);
    }

    public function deleteDocument(string $path): bool
    {
        return $this->storage->deleteFile($path);
    }

    public function getDocumentUrl(string $path): ?string
    {
        if (!$this->storage->fileExists($path)) {
            return null;
        }

        return $this->storage->getFileUrl($path);
    }

    private function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $basename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $timestamp = time();
        $random = bin2hex(random_bytes(8));

        return sprintf('%s_%s_%s.%s', $basename, $timestamp, $random, $extension);
    }
}
```

## Storage Backends

### Local Storage

The local storage backend stores files in the server's filesystem:

```
var/tenant_storage/
├── tenant_acme/
│   ├── documents/
│   │   ├── contract_123.pdf
│   │   └── invoice_456.pdf
│   ├── images/
│   │   ├── originals/
│   │   ├── thumbnails/
│   │   └── medium/
│   └── uploads/
└── tenant_techstartup/
    ├── documents/
    ├── images/
    └── uploads/
```

### S3 Storage

The S3 storage backend uses AWS S3 or S3-compatible services:

```
my-tenant-bucket/
├── tenant_acme/
│   ├── documents/
│   ├── images/
│   └── uploads/
└── tenant_techstartup/
    ├── documents/
    ├── images/
    └── uploads/
```

## Best Practices

1. **Validate All Uploads**: Always validate file types, sizes, and content
2. **Use Unique Filenames**: Prevent filename collisions with timestamps/UUIDs
3. **Implement Access Control**: Verify user permissions before file operations
4. **Monitor Storage Usage**: Track storage usage per tenant
5. **Backup Important Files**: Implement backup strategies for critical files
6. **Optimize Images**: Automatically resize/optimize uploaded images
7. **Use CDN**: Consider CDN for public file delivery
8. **Clean Up Temporary Files**: Remove temporary files after processing
9. **Log File Operations**: Log uploads, downloads, and deletions for audit
10. **Handle Large Files**: Implement chunked uploads for large files