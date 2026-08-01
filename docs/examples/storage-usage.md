# Tenant-Aware Storage Example

Enable local storage and inject `TenantFileStorageInterface`:

```yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: 'local'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
```

```php
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

final readonly class DocumentStorage
{
    public function __construct(private TenantFileStorageInterface $storage)
    {
    }

    public function upload(UploadedFile $file): string
    {
        return $this->storage->uploadFile($file, 'documents/'.$file->getClientOriginalName());
    }

    public function remove(string $filename): void
    {
        $this->storage->delete('documents/'.$filename);
    }
}
```

The active tenant `acme` stores the file under `tenants/acme/documents/...`. Calls without an active tenant throw `TenantStorageException`. Never substitute a `default` tenant or trim user input into a valid-looking path. Validate user-facing filenames before calling storage; the adapter additionally rejects traversal, encoded input, ambiguous separators, null bytes, and symbolic-link escapes.

Global assets belong behind a separate application service rooted at an explicit `global/` namespace.
