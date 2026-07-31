# Tenant-Aware Mailer Example

By default, tenant routing and sender settings are private implementation details and no tenant metadata header is emitted.

```yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'Application'
        add_tenant_id_header: false
        add_tenant_name_header: false
```

```php
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;

$email = (new Email())
    ->to('recipient@example.com')
    ->subject('Welcome')
    ->text('Welcome to the application.');

$mailer->send($email);
```

To expose only the tenant slug to a trusted receiver:

```yaml
zhortein_multi_tenant:
    mailer:
        add_tenant_id_header: true
        add_tenant_name_header: false
```

Enable `add_tenant_name_header` separately only when the display name is required and permitted by the application's privacy policy. Existing application-provided `X-Tenant-ID` or `X-Tenant-Name` values are preserved.
