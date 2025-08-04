# Tenant-Aware Mailer Usage Examples

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
        // Set tenant-specific mailer DSN
        $this->settingsManager->set('mailer_dsn', 'smtp://user:pass@smtp.example.com:587');
        
        // Set sender information
        $this->settingsManager->set('email_sender', 'Company Name');
        $this->settingsManager->set('email_from', 'noreply@tenant.example.com');
        
        // Optional: Set reply-to address
        $this->settingsManager->set('email_reply_to', 'support@tenant.example.com');
    }
}
```

### 2. Service Configuration

```yaml
# config/services.yaml
services:
    # The tenant-aware mailer will automatically be used
    Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer:
        decorates: 'mailer'
        arguments:
            $mailer: '@.inner'
            $configurator: '@Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator'

    # Transport factory for dynamic DSN resolution
    Zhortein\MultiTenantBundle\Mailer\TenantMailerTransportFactory:
        tags:
            - { name: 'mailer.transport_factory' }
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
            ->to($userEmail)
            ->subject('Welcome to our platform!')
            ->html('<h1>Welcome ' . $userName . '!</h1>');

        $this->mailer->send($email);
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

## Best Practices

1. **Always provide fallback values** when retrieving tenant settings
2. **Use environment variables** for sensitive information like SMTP passwords
3. **Test email configuration** before deploying to production
4. **Monitor email delivery** and handle failures gracefully
5. **Use tenant-specific templates** for better branding consistency