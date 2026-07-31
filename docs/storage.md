# Tenant-Aware Storage

Tenant storage is fail-closed and uses an explicit namespace for every active tenant.

## Contract

All operations on `TenantFileStorageInterface` require an active tenant context. If the context is missing, they throw `TenantStorageException`; missing context never means global storage. Tenant paths have this shape:

```text
tenants/{safe-tenant-slug}/{relative-path}
```

The tenant slug `default` therefore maps to `tenants/default/...` and cannot collide with a global namespace. The same validation applies to upload, delete, existence checks, URL/path generation, and listing.

Paths must be non-empty relative paths, except that the empty directory is accepted by `listFiles()`. Components may contain ASCII letters, digits, dots, underscores, and hyphens, and must start with a letter or digit. Absolute paths, dot segments, repeated or backslash separators, null bytes, percent-encoded input, drive paths, and unsafe tenant identifiers are rejected before backend access. Local storage also rejects symbolic links in the configured base path or tenant tree.

The interface has no move operation. Applications that implement a move must perform both source and destination validation through the same tenant-aware adapter; copying a fully qualified tenant path between contexts is unsupported.

## Configuration

```yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: 'local'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
```

The local layout is:

```text
var/tenant_storage/
`-- tenants/
    |-- acme/
    |   `-- documents/contract.pdf
    `-- default/
        `-- documents/example.pdf
```

## Usage

Pass only a path relative to the active tenant root:

```php
$storedPath = $storage->uploadFile($uploadedFile, 'documents/contract.pdf');
$exists = $storage->exists('documents/contract.pdf');
$url = $storage->getUrl('documents/contract.pdf');
$files = $storage->listFiles('documents');
$storage->delete('documents/contract.pdf');
```

Do not pass the returned `tenants/{slug}/...` path back into the adapter; public operations add the namespace themselves.

## Explicit global storage

Global files must use a separate application service and an explicit namespace such as `global/...`. Do not alias the tenant-aware service as global storage and do not derive global access from `TenantContextInterface::getTenant() === null`.

For local files, an application can register a dedicated service around Symfony Filesystem with a fixed root such as `%kernel.project_dir%/var/storage/global`. Keep its interface distinct from `TenantFileStorageInterface`, validate relative paths independently, and apply the same symlink policy.

See [Security contract migration](migration-security-contracts.md) for existing-file migration.
