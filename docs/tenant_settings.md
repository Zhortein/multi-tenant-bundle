
# 🎛️ Tenant Settings

Le bundle propose une entité `TenantSetting` pour stocker des paires clé/valeur spécifiques à chaque tenant.

---

## 📦 Entité Doctrine

L'entité `TenantSetting` est définie avec les colonnes suivantes :

| Champ     | Type     | Description                             |
|-----------|----------|-----------------------------------------|
| id        | integer  | ID auto-incrémenté                      |
| tenant    | relation | Relation ManyToOne vers `TenantInterface` |
| key       | string   | Clé du paramètre (unique pour le tenant) |
| value     | text     | Valeur du paramètre (optionnelle)       |

Un index unique est placé sur la combinaison `tenant_id` + `key`.

---

## 🔍 Repository `TenantSettingRepository`

Méthode utile disponible :

```php
/**
 * @return TenantSetting[]
 */
public function findAllForTenant(TenantInterface $tenant): array
```

---

## 🧠 Utilisation dans votre application

Vous pouvez stocker et récupérer dynamiquement des paramètres comme des DSN, des emails, des préférences d’affichage, etc.

### Exemple d’ajout

```php
$setting = new TenantSetting();
$setting->setTenant($tenant);
$setting->setKey('email_from');
$setting->setValue('contact@mairie-genlis.fr');
$em->persist($setting);
$em->flush();
```

### Exemple de récupération

```php
$settings = $repository->findAllForTenant($tenant);
foreach ($settings as $setting) {
    echo $setting->getKey() . ': ' . $setting->getValue();
}
```

---

## 🧰 Bonnes pratiques

- Préfixez vos clés (`email_`, `messenger_`, `config_`…) pour éviter les conflits.
- Utilisez un cache ou un service de centralisation si vous accédez fréquemment aux paramètres.
