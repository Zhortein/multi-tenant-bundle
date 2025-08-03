# ⚙️ Services helper du bundle

Le bundle propose des **helpers activables** pour accélérer l'intégration multi-tenant.

---

## 🧭 Sommaire

- [`TenantAssetUploader`](#tenantassetuploader)
- [`TenantMailerHelper`](#tenantmailerhelper)
- [`TenantMessengerConfigurator`](#tenantmessengerconfigurator)
- [Fallbacks & comportements par défaut](#-fallbacks--comportements-par-défaut)
- [Stratégie de cache](#-stratégie-de-cache)

---

## `TenantAssetUploader`

Service pour uploader un fichier avec un nom unique dans un répertoire tenant-aware.

### ✅ Activation (par défaut activé)

```yaml
zhortein_multi_tenant:
  helpers:
    asset_uploader: true
```

### 📦 Utilisation

```php
use Zhortein\MultiTenantBundle\Helper\TenantAssetUploader;

$path = $uploader->upload($file, 'logos');
```

---

## `TenantMailerHelper`

Génère des emails tenant-aware avec les bons `from`, `reply-to`, etc.

### ✅ Activation

```yaml
zhortein_multi_tenant:
  helpers:
    mailer_helper: true
```

### ⚙️ Paramètres requis dans `TenantSettings`

| Clé             | Description                    | Exemple                    |
|-----------------|--------------------------------|----------------------------|
| `email_sender`  | Nom de l'expéditeur            | Mairie de Genlis           |
| `email_from`    | Adresse d'envoi                | contact@genlis.fr          |
| `email_reply_to`| Adresse de réponse (facultatif)| secretariat@genlis.fr      |

### 📦 Utilisation

```php
$email = $mailer->createEmail()
    ->to('user@example.com')
    ->subject('Bienvenue')
    ->htmlTemplate('emails/welcome.html.twig');

$mailer->send($email);
```

---

## `TenantMessengerConfigurator`

Adapte les transports ou le bus Messenger en fonction du tenant courant.

### ⚙️ Clés supportées dans `TenantSettings`

| Clé                              | Rôle                                    | Exemple                      |
|----------------------------------|-----------------------------------------|------------------------------|
| `messenger_transport_dsn`       | DSN du transport `async`                | `sqs://key:secret@default`   |
| `messenger_bus`                 | Bus à utiliser pour dispatch            | `messenger.bus.default`      |
| `messenger_delay_notifications` | Délai pour transport `notifications`    | `2000` (en millisecondes)    |

---

## 🧰 Fallbacks & comportements par défaut

Si un paramètre est manquant dans les `TenantSettings`, le bundle :

- utilise les valeurs de fallback définies dans la config Symfony (si possible),
- sinon lève une **exception explicite** avec un message clair,
- vous pouvez surcharger ces comportements avec vos propres services.

---

## ⚡ Stratégie de cache

Le bundle **met en cache automatiquement** certaines données sensibles pour chaque tenant :

- Résolution du tenant courant (`TenantContext`)
- Résolution du DSN Mailer/Messenger si activé
- Résolution des paramètres injectés via `TenantSettings`

Le cache utilise par défaut la `cache.app` Symfony mais peut être personnalisé.
