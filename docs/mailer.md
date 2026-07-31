# Tenant-Aware Mailer

The tenant-aware mailer applies tenant routing and sender configuration without exposing tenant metadata in public message headers by default.

## Configuration

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

`add_tenant_id_header` controls only `X-Tenant-ID` and uses the tenant slug. `add_tenant_name_header` independently controls only `X-Tenant-Name`. Both default to `false`; enabling the identifier never enables the name.

Header values must be non-empty and cannot contain CR, LF, or null bytes. Symfony Mime performs the final encoding. If an application already supplied the configured header, the bundle preserves it rather than overwriting it.

## Tenant configuration

`TenantMailerConfigurator` reads these tenant settings:

| Setting | Purpose |
| --- | --- |
| `mailer_dsn` | Tenant transport DSN |
| `email_from` | Sender address |
| `email_sender` | Sender display name |
| `email_reply_to` | Reply-to address |
| `email_bcc` | BCC address |
| `logo_url`, `primary_color`, `website_url` | Template branding |

Routing, sender selection, reply-to, BCC, and template context continue to work when both public metadata headers are disabled.

## Asynchronous delivery

The headers are applied to the `Email` before it is passed to the inner Mailer. Symfony Messenger serializes that resulting message, so the configured opt-in behavior is preserved across asynchronous delivery. Tenant context propagation for application messages remains the responsibility of the bundle Messenger middleware described in [Messenger](messenger.md).

Public email headers can cross organizational boundaries. Enable them only when a receiving system requires them and its privacy model permits disclosure.
