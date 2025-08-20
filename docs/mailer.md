# Tenant-Aware Mailer

The Zhortein Multi-Tenant Bundle provides comprehensive tenant-aware email functionality through its mailer integration. This allows you to send emails with tenant-specific configurations, templates, and branding.

> 📖 **Navigation**: [← Tenant Settings](tenant-settings.md) | [Back to Documentation Index](index.md) | [Messenger →](messenger.md)

## Overview

The tenant-aware mailer system consists of several components:

- **TenantMailerConfigurator**: Manages tenant-specific email settings
- **TenantAwareMailer**: Automatically configures emails based on tenant context with templated email support
- **TenantMailerHelper**: Simplified interface for common email operations

## Requirements

The mailer functionality requires the following packages:

```bash
composer require symfony/mailer
composer require symfony/twig-bundle  # For templated emails
```

## Configuration

Enable the mailer integration in your bundle configuration:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'Default Sender'
```

### Configuration Options

- `enabled`: Enable/disable tenant-aware mailer functionality
- `fallback_dsn`: Default mailer DSN when tenant has no specific configuration
- `fallback_from`: Default from address when tenant has no specific configuration
- `fallback_sender`: Default sender name when tenant has no specific configuration

## Tenant Settings

Configure email settings per tenant using the tenant settings system:

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

public function configureEmail(TenantSettingsManager $settings): void
{
    // Email configuration
    $settings->set('mailer_dsn', 'smtp://smtp.tenant.com:587');
    $settings->set('email_from', 'noreply@tenant.com');
    $settings->set('email_sender', 'Tenant Name');
    $settings->set('email_reply_to', 'support@tenant.com');
    $settings->set('email_bcc', 'admin@tenant.com');
    
    // Branding settings
    $settings->set('logo_url', 'https://cdn.tenant.com/logo.png');
    $settings->set('primary_color', '#ff6b35');
    $settings->set('website_url', 'https://tenant.com');
}
```

### Available Settings

| Setting Key | Description | Example |
|-------------|-------------|---------|
| `mailer_dsn` | SMTP/transport configuration | `smtp://user:pass@smtp.gmail.com:587` |
| `email_from` | Default from address | `noreply@tenant.com` |
| `email_sender` | Default sender name | `Acme Corporation` |
| `email_reply_to` | Default reply-to address | `support@tenant.com` |
| `email_bcc` | Default BCC address | `admin@tenant.com` |
| `logo_url` | Tenant logo URL | `https://cdn.tenant.com/logo.png` |
| `primary_color` | Tenant primary color | `#ff6b35` |
| `website_url` | Tenant website URL | `https://tenant.com` |

## Usage

### Basic Email Sending

The tenant-aware mailer automatically configures emails based on the current tenant context:

```php
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

public function sendEmail(MailerInterface $mailer): void
{
    $email = (new Email())
        ->to('user@example.com')
        ->subject('Welcome!')
        ->text('Welcome to our platform!');
    
    // Tenant-specific configuration is applied automatically
    // Headers X-Tenant-ID and X-Tenant-Name are added automatically
    $mailer->send($email);
}
```

### Templated Emails with TenantAwareMailer

The enhanced `TenantAwareMailer` provides a convenient method for sending templated emails:

```php
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;

public function sendWelcomeEmail(TenantAwareMailer $mailer, string $userEmail): void
{
    $mailer->sendTemplatedEmail(
        to: $userEmail,
        subject: 'Welcome to our platform!',
        template: 'emails/welcome.html.twig',
        context: [
            'user' => $user,
            'activationUrl' => $this->generateUrl('activate', ['token' => $token])
        ]
    );
}
```

### Using TenantMailerHelper

For simplified email operations, use the helper service:

```php
use Zhortein\MultiTenantBundle\Helper\TenantMailerHelper;

public function sendNotification(TenantMailerHelper $helper, string $userEmail): void
{
    $helper->sendEmail(
        to: $userEmail,
        subject: 'Important Notification',
        template: 'emails/notification.html.twig',
        context: ['message' => 'Your account has been updated']
    );
}
```

## Template System

### Tenant-Specific Templates

The mailer supports tenant-specific email templates with automatic fallback. Place templates in:

```
templates/
├── emails/
│   ├── tenant/
│   │   ├── acme/
│   │   │   ├── welcome.html.twig
│   │   │   ├── notification.html.twig
│   │   │   └── layout.html.twig
│   │   └── bio/
│   │       ├── welcome.html.twig
│   │       ├── notification.html.twig
│   │       └── layout.html.twig
│   ├── welcome.html.twig      # Default template
│   ├── notification.html.twig # Default template
│   └── layout.html.twig       # Default layout
```

### Template Resolution

The system automatically resolves templates in this order:

1. Tenant-specific template: `emails/tenant/{slug}/template.html.twig`
2. Default template: `emails/template.html.twig`

### Template Context

All email templates automatically receive tenant-specific context variables:

```twig
{# emails/welcome.html.twig #}
{% extends 'emails/layout.html.twig' %}

{% block content %}
<h1>Welcome to {{ tenant.name }}!</h1>
<p>Thank you for joining us, {{ user.name }}!</p>

{% if tenant.logoUrl %}
    <img src="{{ tenant.logoUrl }}" alt="{{ tenant.name }} Logo" style="max-width: 200px;">
{% endif %}

<p style="color: {{ tenant.primaryColor|default('#007bff') }}">
    Visit our website: <a href="{{ tenant.websiteUrl }}">{{ tenant.websiteUrl }}</a>
</p>

<a href="{{ activationUrl }}" 
   style="background-color: {{ tenant.primaryColor|default('#007bff') }}; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
    Activate Account
</a>
{% endblock %}
```

### Available Template Variables

The `tenant` object is automatically injected into all email templates:

| Variable | Description | Example |
|----------|-------------|---------|
| `tenant.name` | Tenant display name | `Acme Corporation` |
| `tenant.slug` | Tenant identifier | `acme` |
| `tenant.logoUrl` | Tenant logo URL | `https://cdn.example.com/logos/acme.png` |
| `tenant.primaryColor` | Tenant primary color | `#ff6b35` |
| `tenant.websiteUrl` | Tenant website URL | `https://acme.com` |

### Base Layout Template

Create a base layout for consistent email styling:

```twig
{# emails/layout.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ tenant.name ?? 'Application' }}</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            line-height: 1.6; 
            color: #333; 
        }
        .header { 
            background-color: {{ tenant.primaryColor|default('#007bff') }}; 
            color: white; 
            padding: 20px; 
            text-align: center; 
        }
        .content { 
            padding: 20px; 
        }
        .footer { 
            background-color: #f8f9fa; 
            padding: 10px; 
            text-align: center; 
            font-size: 12px; 
        }
    </style>
</head>
<body>
    <div class="header">
        {% if tenant.logoUrl %}
            <img src="{{ tenant.logoUrl }}" alt="{{ tenant.name }} Logo" style="max-height: 50px;">
        {% endif %}
        <h1>{{ tenant.name ?? 'Application' }}</h1>
    </div>
    
    <div class="content">
        {% block content %}{% endblock %}
    </div>
    
    <div class="footer">
        <p>&copy; {{ "now"|date("Y") }} {{ tenant.name ?? 'Application' }}. All rights reserved.</p>
        {% if tenant.websiteUrl %}
            <p><a href="{{ tenant.websiteUrl }}">{{ tenant.websiteUrl }}</a></p>
        {% endif %}
    </div>
</body>
</html>
```

## Advanced Usage

### Custom Email Headers

The system automatically adds tenant-specific headers:

```php
// Headers added automatically by TenantAwareMailer:
// X-Tenant-ID: acme
// X-Tenant-Name: Acme Corporation
```

### Manual Configuration Access

You can access tenant-specific configuration directly:

```php
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

public function getEmailConfig(TenantMailerConfigurator $configurator): array
{
    return [
        'from' => $configurator->getFromAddress(),
        'sender' => $configurator->getSenderName(),
        'replyTo' => $configurator->getReplyToAddress(),
        'bcc' => $configurator->getBccAddress(),
        'logoUrl' => $configurator->getLogoUrl(),
        'primaryColor' => $configurator->getPrimaryColor(),
        'websiteUrl' => $configurator->getWebsiteUrl(),
    ];
}
```

### Override From Address

You can override the from address for specific emails:

```php
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;

public function sendSystemEmail(TenantAwareMailer $mailer): void
{
    $mailer->sendTemplatedEmail(
        to: 'user@example.com',
        subject: 'System Notification',
        template: 'emails/system.html.twig',
        context: ['message' => 'System maintenance scheduled'],
        fromOverride: 'system@example.com'
    );
}
```

## Testing

### Unit Testing

Test your email functionality with tenant context:

```php
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;

class EmailTest extends TestCase
{
    public function testTenantTemplatedEmail(): void
    {
        // Set up tenant context
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corp');
        
        $context = $this->createMock(TenantContext::class);
        $context->method('getTenant')->willReturn($tenant);
        
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        
        // Test templated email sending
        $tenantMailer = new TenantAwareMailer($mailer, $configurator, $context, $twig);
        $tenantMailer->sendTemplatedEmail(
            'user@example.com', 
            'Test', 
            'test.html.twig',
            ['data' => 'value']
        );
        
        // Assert email was sent with correct configuration
    }
}
```

### Integration Testing

Test the complete email flow:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EmailIntegrationTest extends KernelTestCase
{
    public function testTenantEmailIntegration(): void
    {
        self::bootKernel();
        
        // Set tenant context
        $tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $tenantContext->setTenant($tenant);
        
        // Send templated email
        $mailer = self::getContainer()->get(TenantAwareMailer::class);
        $mailer->sendTemplatedEmail(
            'test@example.com',
            'Integration Test',
            'emails/test.html.twig'
        );
        
        // Verify tenant-specific configuration was applied
    }
}
```

## Troubleshooting

### Common Issues

1. **Templates not found**: Ensure templates are in the correct directory structure
2. **Twig not available**: Install `symfony/twig-bundle` for templated emails
3. **Settings not applied**: Verify tenant context is properly set
4. **Transport errors**: Check tenant-specific DSN configuration

### Debug Information

Enable debug mode to see detailed mailer information:

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            channels: ["!event"]
```

### Error Handling

The bundle gracefully handles missing dependencies:

```php
// TenantAwareMailer will throw RuntimeException if Twig is not available
// when using sendTemplatedEmail() method
try {
    $mailer->sendTemplatedEmail(...);
} catch (\RuntimeException $e) {
    // Handle missing Twig dependency
    $this->logger->error('Twig required for templated emails', ['error' => $e->getMessage()]);
}
```

## Best Practices

1. **Template Organization**: Keep tenant-specific templates organized in subdirectories
2. **Fallback Configuration**: Always provide fallback settings for reliability
3. **Template Inheritance**: Use base layouts for consistent email styling
4. **Context Variables**: Leverage automatic tenant context injection
5. **Testing**: Test email functionality with different tenant configurations
6. **Performance**: Cache tenant settings to avoid repeated database queries
7. **Security**: Validate tenant-specific email addresses and DSNs
8. **Error Handling**: Handle missing dependencies gracefully

## Examples

See the [Mailer Usage Examples](examples/mailer-usage.md) for practical implementation examples.