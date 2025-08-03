### 🧩 Base de données par tenant

Pour utiliser le résolveur par défaut, votre entité `Tenant` doit exposer :

- `getDatabaseName()`
- `getDatabaseUser()`
- `getDatabasePassword()`
- `getDatabaseHost()`
- `getDatabasePort()`
- `getDatabaseDriver()`

Un `Trait` est fourni dans le bundle pour simplifier l’implémentation :

```php
use Zhortein\MultiTenantBundle\Entity\Trait\TenantDatabaseInfoTrait;

class Tenant implements TenantInterface
{
    use TenantDatabaseInfoTrait;

    // Vos autres champs...
}
```

Vous pouvez bien entendu créer un resolver personnalisé pour mapper vos colonnes autrement.
