# Configuration

Voici la configuration minimale possible pour le bundle :

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
  tenant_entity: App\Entity\Tenant
  resolver: path # ou subdomain
```

Le bundle va automatiquement :

* injecter le resolver correspondant
* enregistrer un listener qui résout le tenant au début de chaque requête
* exposer un TenantContext injectable via autowiring

# Paramètres par tenant

Le bundle vous permet de stocker dynamiquement des paires clé/valeur pour chaque tenant, via la table `tenant_setting`.

## Utilisation

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

public function __construct(
    private readonly TenantSettingsManager $settings
) {}

public function show(): void
{
    $supportEmail = $this->settings->get('support_email', 'contact@example.com');

    if (!$this->settings->has('debug_mode')) {
        $this->settings->set('debug_mode', false);
    }
}
```

## Avantages

* 🧠 Lazy loading + cache mémoire
* ✅ Compatible avec Doctrine filter
* 💾 Persistance automatique
* 🔄 Modifiable depuis une interface admin
