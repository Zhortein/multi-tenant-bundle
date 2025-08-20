# Tenant-Aware Mailer Usage Examples

This document provides practical examples of using the tenant-aware mailer functionality with the enhanced templated email support.

> 📖 **Navigation**: [← DNS TXT Resolver Usage](dns-txt-resolver-usage.md) | [Back to Documentation Index](../index.md) | [Messenger Usage →](messenger-usage.md)

## Basic Configuration

### 1. Configure Tenant Settings

```php
// In your controller or service
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantConfigurationController
{
    public function __construct(
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureMailer(): void
    {
        // Email transport configuration
        $this->settingsManager->set('mailer_dsn', 'smtp://user:pass@smtp.example.com:587');
        
        // Sender information
        $this->settingsManager->set('email_sender', 'Acme Corporation');
        $this->settingsManager->set('email_from', 'noreply@acme.example.com');
        $this->settingsManager->set('email_reply_to', 'support@acme.example.com');
        $this->settingsManager->set('email_bcc', 'admin@acme.example.com');
        
        // Branding settings for templates
        $this->settingsManager->set('logo_url', 'https://cdn.acme.com/logo.png');
        $this->settingsManager->set('primary_color', '#ff6b35');
        $this->settingsManager->set('website_url', 'https://acme.com');
    }
}
```

### 2. Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'Default Application'
```

## Usage Examples

### 1. Basic Email Sending

```php
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NotificationService
{
    public function __construct(
        private MailerInterface $mailer // This will be the TenantAwareMailer
    ) {}

    public function sendWelcomeEmail(string $userEmail, string $userName): void
    {
        $email = (new Email())
            // From address will be automatically set based on tenant settings
            // Headers X-Tenant-ID and X-Tenant-Name are added automatically
            ->to($userEmail)
            ->subject('Welcome to our platform!')
            ->html('<h1>Welcome ' . $userName . '!</h1>');

        $this->mailer->send($email);
    }
}
```

### 2. Templated Emails with TenantAwareMailer

```php
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;

class UserService
{
    public function __construct(
        private TenantAwareMailer $mailer
    ) {}

    public function sendWelcomeEmail(string $userEmail, array $user): void
    {
        // Sends email using tenant-specific template with automatic tenant context
        $this->mailer->sendTemplatedEmail(
            to: $userEmail,
            subject: 'Welcome to our platform!',
            template: 'emails/welcome.html.twig',
            context: [
                'user' => $user,
                'activationUrl' => $this->generateActivationUrl($user['id'])
            ]
        );
    }

    public function sendPasswordReset(string $userEmail, string $resetToken): void
    {
        $this->mailer->sendTemplatedEmail(
            to: $userEmail,
            subject: 'Password Reset Request',
            template: 'emails/password-reset.html.twig',
            context: [
                'resetUrl' => $this->generateResetUrl($resetToken),
                'expiresAt' => new \DateTime('+1 hour')
            ]
        );
    }

    public function sendSystemNotification(string $userEmail, string $message): void
    {
        // Override from address for system emails
        $this->mailer->sendTemplatedEmail(
            to: $userEmail,
            subject: 'System Notification',
            template: 'emails/system-notification.html.twig',
            context: ['message' => $message],
            fromOverride: 'system@example.com'
        );
    }
}
```

### 2. Advanced Email with Tenant Branding

```php
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class BrandedEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantContextInterface $tenantContext,
        private TenantSettingsManager $settingsManager
    ) {}

    public function sendBrandedNotification(string $userEmail, string $subject, string $content): void
    {
        $tenant = $this->tenantContext->getTenant();
        $companyName = $this->settingsManager->get('company_name', 'Our Company');
        $brandColor = $this->settingsManager->get('brand_color', '#007bff');

        $email = (new Email())
            ->to($userEmail)
            ->subject($subject)
            ->html($this->buildBrandedTemplate($content, $companyName, $brandColor));

        // Add tenant-specific headers
        $email->getHeaders()
            ->addTextHeader('X-Tenant-ID', (string) $tenant?->getId())
            ->addTextHeader('X-Tenant-Slug', $tenant?->getSlug() ?? 'default');

        $this->mailer->send($email);
    }

    private function buildBrandedTemplate(string $content, string $companyName, string $brandColor): string
    {
        return sprintf(
            '<div style="color: %s; font-family: Arial, sans-serif;">
                <h1>%s</h1>
                <div>%s</div>
                <footer style="margin-top: 20px; font-size: 12px; color: #666;">
                    © %s. All rights reserved.
                </footer>
            </div>',
            $brandColor,
            $companyName,
            $content,
            $companyName
        );
    }
}
```

### 3. Fallback Configuration

```php
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

class EmailService
{
    public function __construct(
        private TenantMailerConfigurator $configurator
    ) {}

    public function getMailerConfiguration(): array
    {
        return [
            'dsn' => $this->configurator->getMailerDsn('smtp://localhost:1025'), // Fallback DSN
            'from' => $this->configurator->getFromAddress('noreply@example.com'),
            'sender' => $this->configurator->getSenderName('Default Company'),
            'reply_to' => $this->configurator->getReplyToAddress('support@example.com'),
        ];
    }
}
```

## Console Commands

### Set Tenant Mailer Settings

```bash
# Set mailer DSN for a specific tenant
php bin/console tenant:settings:set tenant-slug mailer_dsn "smtp://user:pass@smtp.tenant.com:587"

# Set sender information
php bin/console tenant:settings:set tenant-slug email_sender "Tenant Company Name"
php bin/console tenant:settings:set tenant-slug email_from "noreply@tenant.com"
php bin/console tenant:settings:set tenant-slug email_reply_to "support@tenant.com"

# View current mailer settings
php bin/console tenant:settings:get tenant-slug mailer_dsn
```

## Testing

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

class TenantAwareMailerTest extends TestCase
{
    public function testSendEmailWithTenantConfiguration(): void
    {
        $innerMailer = $this->createMock(MailerInterface::class);
        $configurator = $this->createMock(TenantMailerConfigurator::class);

        $configurator->expects($this->once())
            ->method('getFromAddress')
            ->willReturn('tenant@example.com');

        $configurator->expects($this->once())
            ->method('getSenderName')
            ->willReturn('Tenant Company');

        $mailer = new TenantAwareMailer($innerMailer, $configurator);

        $email = new Email();
        $email->to('user@example.com')
              ->subject('Test')
              ->text('Test content');

        $innerMailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                $from = $email->getFrom();
                return count($from) === 1 
                    && $from[0]->getAddress() === 'tenant@example.com'
                    && $from[0]->getName() === 'Tenant Company';
            }));

        $mailer->send($email);
    }
}
```

## Configuration Reference

### Available Tenant Settings

| Setting Key | Description | Example Value |
|-------------|-------------|---------------|
| `mailer_dsn` | SMTP/transport DSN | `smtp://user:pass@smtp.example.com:587` |
| `email_sender` | Sender name | `"Company Name"` |
| `email_from` | From email address | `"noreply@tenant.com"` |
| `email_reply_to` | Reply-to address | `"support@tenant.com"` |

### Transport Types Supported

- **SMTP**: `smtp://user:pass@host:port`
- **Gmail**: `gmail://user:pass@default`
- **SendGrid**: `sendgrid://key@default`
- **Mailgun**: `mailgun://key:domain@default`
- **Amazon SES**: `ses://access_key:secret_key@default?region=us-east-1`
- **Null (testing)**: `null://null`

## Template Examples

### Base Layout Template

```twig
{# templates/emails/layout.html.twig #}
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
            margin: 0; 
            padding: 0; 
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #ffffff; 
        }
        .header { 
            background-color: {{ tenant.primaryColor|default('#007bff') }}; 
            color: white; 
            padding: 20px; 
            text-align: center; 
        }
        .content { 
            padding: 30px 20px; 
        }
        .footer { 
            background-color: #f8f9fa; 
            padding: 20px; 
            text-align: center; 
            font-size: 14px; 
            color: #666; 
        }
        .button {
            display: inline-block;
            background-color: {{ tenant.primaryColor|default('#007bff') }};
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {% if tenant.logoUrl %}
                <img src="{{ tenant.logoUrl }}" alt="{{ tenant.name }} Logo" style="max-height: 60px; margin-bottom: 10px;">
            {% endif %}
            <h1>{{ tenant.name ?? 'Application' }}</h1>
        </div>
        
        <div class="content">
            {% block content %}{% endblock %}
        </div>
        
        <div class="footer">
            <p>&copy; {{ "now"|date("Y") }} {{ tenant.name ?? 'Application' }}. All rights reserved.</p>
            {% if tenant.websiteUrl %}
                <p><a href="{{ tenant.websiteUrl }}" style="color: {{ tenant.primaryColor|default('#007bff') }};">Visit our website</a></p>
            {% endif %}
        </div>
    </div>
</body>
</html>
```

### Welcome Email Template

```twig
{# templates/emails/welcome.html.twig #}
{% extends 'emails/layout.html.twig' %}

{% block content %}
<h2>Welcome to {{ tenant.name }}!</h2>

<p>Hello {{ user.name }},</p>

<p>Thank you for joining {{ tenant.name }}! We're excited to have you as part of our community.</p>

<p>To get started, please activate your account by clicking the button below:</p>

<p style="text-align: center;">
    <a href="{{ activationUrl }}" class="button">Activate Account</a>
</p>

<p>If the button doesn't work, you can also copy and paste this link into your browser:</p>
<p><a href="{{ activationUrl }}">{{ activationUrl }}</a></p>

<p>If you have any questions, feel free to contact our support team.</p>

<p>Best regards,<br>
The {{ tenant.name }} Team</p>
{% endblock %}
```

### Tenant-Specific Template

```twig
{# templates/emails/tenant/acme/welcome.html.twig #}
{% extends 'emails/layout.html.twig' %}

{% block content %}
<h2>Welcome to Acme Corporation!</h2>

<p>Dear {{ user.name }},</p>

<p>Welcome to the Acme family! As a leading innovator in our industry, we're thrilled to have you join our exclusive platform.</p>

<div style="background-color: #f8f9fa; padding: 20px; border-left: 4px solid {{ tenant.primaryColor }}; margin: 20px 0;">
    <h3>What's Next?</h3>
    <ul>
        <li>Activate your account using the link below</li>
        <li>Complete your profile setup</li>
        <li>Explore our premium features</li>
        <li>Connect with our dedicated account manager</li>
    </ul>
</div>

<p style="text-align: center;">
    <a href="{{ activationUrl }}" class="button">Activate Your Premium Account</a>
</p>

<p>As an Acme member, you'll have access to:</p>
<ul>
    <li>Priority customer support</li>
    <li>Advanced analytics dashboard</li>
    <li>Exclusive industry insights</li>
    <li>Custom integrations</li>
</ul>

<p>Your dedicated account manager will contact you within 24 hours to help you get the most out of your Acme experience.</p>

<p>Welcome aboard!</p>

<p>Best regards,<br>
The Acme Team<br>
<em>Innovation. Excellence. Results.</em></p>
{% endblock %}
```

### Password Reset Template

```twig
{# templates/emails/password-reset.html.twig #}
{% extends 'emails/layout.html.twig' %}

{% block content %}
<h2>Password Reset Request</h2>

<p>Hello,</p>

<p>We received a request to reset your password for your {{ tenant.name }} account.</p>

<p>If you made this request, click the button below to reset your password:</p>

<p style="text-align: center;">
    <a href="{{ resetUrl }}" class="button">Reset Password</a>
</p>

<p><strong>This link will expire at {{ expiresAt|date('Y-m-d H:i:s') }} UTC.</strong></p>

<p>If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>

<p>For security reasons, this link can only be used once.</p>

<p>If you have any concerns about your account security, please contact our support team immediately.</p>

<p>Best regards,<br>
The {{ tenant.name }} Security Team</p>
{% endblock %}
```

## Best Practices

1. **Always provide fallback values** when retrieving tenant settings
2. **Use environment variables** for sensitive information like SMTP passwords
3. **Test email configuration** before deploying to production
4. **Monitor email delivery** and handle failures gracefully
5. **Use tenant-specific templates** for better branding consistency
6. **Implement template inheritance** for maintainable email designs
7. **Test templates** with different tenant configurations
8. **Handle missing Twig gracefully** in production environments