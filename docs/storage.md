# File Storage par tenant

Le bundle fournit une abstraction `TenantFileStorageInterface` avec une implémentation par défaut `LocalStorage`.

### Configuration

```yaml
zhortein_multi_tenant:
  storage:
    default: local
    options:
      local:
        base_path: '%kernel.project_dir%/var/tenants'
        base_url: '/tenants'
```

## Utilisation

Vous pouvez injecter l’interface `TenantFileStorageInterface` comme n’importe quel service Symfony :

```php
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

class MyService
{
    public function __construct(
        private TenantFileStorageInterface $storage
    ) {}

    public function save(File $file): string
    {
        return $this->storage->upload($file, 'documents/' . $file->getFilename());
    }
}
```

