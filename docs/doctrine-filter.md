# Doctrine Filter (tenant_id)

Le bundle fournit un filtre Doctrine automatique qui limite toutes les entités marquées comme tenant-aware.

## Activer le filtre

Ajoutez dans votre `config/packages/doctrine.yaml` :

```yaml
doctrine:
  orm:
    entity_managers:
      default:
        filters:
          tenant_filter:
            class: Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
            enabled: false
```

Le filtre sera activé automatiquement au début de chaque requête HTTP si un tenant est détecté.

## Marquer vos entités

Vos entités doivent :
* Implémenter TenantOwnedEntityInterface
* Avoir une association tenant vers votre entité tenant

```php
#[ORM\ManyToOne(targetEntity: Tenant::class)]
private TenantInterface $tenant;
```

## Tag `[AsTenantAware]`

Vous pouvez tagguer une entité comme filtrable par tenant via un attribut PHP :

```php
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

#[AsTenantAware]
class MyEntity
{
    // ...
}
```

Le bundle activera automatiquement le filtre Doctrine correspondant (tenant_filter) à la requête.


