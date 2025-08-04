# Tenant-Aware Mailer

The tenant-aware mailer system allows each tenant to have its own email configuration, including SMTP settings, sender information, and email templates. This enables personalized email communication for each tenant while maintaining a unified codebase.

## Overview

The mailer integration provides:

- **Tenant-specific SMTP configuration**: Each tenant can use different email providers
- **Dynamic sender information**: Customize from address and sender name per tenant
- **Fallback configuration**: Global defaults when tenant settings are not configured
- **Template customization**: Tenant-specific email templates
- **Automatic configuration**: Seamless integration with Symfony Mailer

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: 'smtp://localhost:1025'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'Default Application'
```

### Symfony Mailer Configuration

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        envelope:
            sender: '%env(MAILER_FROM)%'
        headers:
            From: '%env(MAILER_FROM)%'
```

### Environment Variables

```bash
# .env
MAILER_DSN=smtp://localhost:1025
MAILER_FROM=noreply@example.com
```

## Basic Usage

### Injecting the Mailer Configurator

```php
<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantMailerConfigurator $mailerConfigurator,
        private TenantContextInterface $tenantContext,
    ) {}

    public function sendWelcomeEmail(string $recipientEmail, string $recipientName): void
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Get tenant-specific email configuration
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        $email = (new Email())
            ->from($fromAddress)
            ->to($recipientEmail)
            ->subject(sprintf('Welcome to %s!', $tenant->getName()))
            ->html($this->renderWelcomeTemplate($recipientName, $tenant));

        // Set sender name if configured
        if ($senderName) {
            $email->from(sprintf('%s <%s>', $senderName, $fromAddress));
        }

        $this->mailer->send($email);
    }

    private function renderWelcomeTemplate(string $name, TenantInterface $tenant): string
    {
        return sprintf(
            '<h1>Welcome to %s, %s!</h1><p>Thank you for joining us.</p>',
            htmlspecialchars($tenant->getName()),
            htmlspecialchars($name)
        );
    }
}
```

### Controller Usage

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\EmailService;

class UserController extends AbstractController
{
    #[Route('/users/invite', name: 'user_invite', methods: ['POST'])]
    public function inviteUser(Request $request, EmailService $emailService): Response
    {
        $email = $request->request->get('email');
        $name = $request->request->get('name');

        try {
            $emailService->sendWelcomeEmail($email, $name);
            $this->addFlash('success', 'Invitation sent successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send invitation: ' . $e->getMessage());
        }

        return $this->redirectToRoute('user_list');
    }
}
```

## Advanced Configuration

### Tenant-Specific Settings

Each tenant can configure their own email settings through the tenant settings system:

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantEmailConfigurationService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
        private TenantContextInterface $tenantContext,
    ) {}

    public function configureEmailSettings(array $settings): void
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Validate email settings
        $this->validateEmailSettings($settings);

        // Set tenant-specific email configuration
        $this->settingsManager->setMultiple([
            'mailer_dsn' => $settings['dsn'],
            'email_from' => $settings['from_address'],
            'email_sender' => $settings['sender_name'],
            'email_reply_to' => $settings['reply_to'] ?? null,
            'smtp_host' => $settings['smtp_host'] ?? null,
            'smtp_port' => $settings['smtp_port'] ?? 587,
            'smtp_encryption' => $settings['smtp_encryption'] ?? 'tls',
            'smtp_username' => $settings['smtp_username'] ?? null,
            'smtp_password' => $settings['smtp_password'] ?? null,
        ]);
    }

    public function getEmailSettings(): array
    {
        return [
            'dsn' => $this->settingsManager->get('mailer_dsn'),
            'from_address' => $this->settingsManager->get('email_from'),
            'sender_name' => $this->settingsManager->get('email_sender'),
            'reply_to' => $this->settingsManager->get('email_reply_to'),
            'smtp_host' => $this->settingsManager->get('smtp_host'),
            'smtp_port' => $this->settingsManager->get('smtp_port', 587),
            'smtp_encryption' => $this->settingsManager->get('smtp_encryption', 'tls'),
            'smtp_username' => $this->settingsManager->get('smtp_username'),
            'smtp_password' => $this->settingsManager->get('smtp_password'),
        ];
    }

    private function validateEmailSettings(array $settings): void
    {
        if (empty($settings['from_address']) || !filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Valid from address is required');
        }

        if (empty($settings['sender_name'])) {
            throw new \InvalidArgumentException('Sender name is required');
        }

        if (!empty($settings['reply_to']) && !filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Reply-to must be a valid email address');
        }
    }
}
```

### Dynamic Mailer Configuration

```php
<?php

namespace App\Service;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\MailerInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

class DynamicMailerService
{
    public function __construct(
        private TenantMailerConfigurator $mailerConfigurator,
    ) {}

    public function createTenantMailer(): MailerInterface
    {
        // Get tenant-specific DSN
        $dsn = $this->mailerConfigurator->getMailerDsn('null://localhost');
        
        // Create transport with tenant-specific configuration
        $transport = Transport::fromDsn($dsn);
        
        return new Mailer($transport);
    }

    public function sendWithTenantConfiguration(Email $email): void
    {
        $mailer = $this->createTenantMailer();
        
        // Apply tenant-specific sender information
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();
        $replyTo = $this->mailerConfigurator->getReplyToAddress();

        if ($fromAddress) {
            $from = $senderName ? sprintf('%s <%s>', $senderName, $fromAddress) : $fromAddress;
            $email->from($from);
        }

        if ($replyTo) {
            $email->replyTo($replyTo);
        }

        $mailer->send($email);
    }
}
```

## Email Templates

### Tenant-Specific Templates

```php
<?php

namespace App\Service;

use Twig\Environment;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantEmailTemplateService
{
    public function __construct(
        private Environment $twig,
        private TenantContextInterface $tenantContext,
    ) {}

    public function renderTemplate(string $template, array $context = []): string
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Add tenant information to template context
        $context['tenant'] = $tenant;
        $context['tenant_name'] = $tenant->getName();
        $context['tenant_slug'] = $tenant->getSlug();

        // Try tenant-specific template first
        $tenantTemplate = sprintf('emails/%s/%s', $tenant->getSlug(), $template);
        
        if ($this->twig->getLoader()->exists($tenantTemplate)) {
            return $this->twig->render($tenantTemplate, $context);
        }

        // Fall back to default template
        return $this->twig->render(sprintf('emails/%s', $template), $context);
    }
}
```

### Template Structure

```
templates/
├── emails/
│   ├── welcome.html.twig          # Default template
│   ├── password_reset.html.twig   # Default template
│   ├── acme/                      # Tenant-specific templates
│   │   ├── welcome.html.twig
│   │   └── password_reset.html.twig
│   └── tech-startup/              # Another tenant's templates
│       ├── welcome.html.twig
│       └── password_reset.html.twig
```

### Default Email Template

```twig
{# templates/emails/welcome.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ tenant_name }}</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { background-color: #f8f9fa; padding: 20px; }
        .content { padding: 20px; }
        .footer { background-color: #e9ecef; padding: 10px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Welcome to {{ tenant_name }}!</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ user_name }},</p>
        
        <p>Thank you for joining {{ tenant_name }}. We're excited to have you on board!</p>
        
        <p>You can access your account at: 
            <a href="{{ app.request.schemeAndHttpHost }}">{{ app.request.schemeAndHttpHost }}</a>
        </p>
        
        <p>If you have any questions, please don't hesitate to contact us.</p>
        
        <p>Best regards,<br>The {{ tenant_name }} Team</p>
    </div>
    
    <div class="footer">
        <p>This email was sent by {{ tenant_name }}.</p>
    </div>
</body>
</html>
```

### Tenant-Specific Template

```twig
{# templates/emails/acme/welcome.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to ACME Corporation</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .logo { max-width: 200px; margin-bottom: 20px; }
        .content { padding: 30px; background-color: #ffffff; }
        .cta-button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer { 
            background-color: #f8f9fa; 
            padding: 20px; 
            text-align: center;
            font-size: 14px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ app.request.schemeAndHttpHost }}/images/acme-logo-white.png" alt="ACME Logo" class="logo">
        <h1>Welcome to ACME Corporation!</h1>
    </div>
    
    <div class="content">
        <p>Dear {{ user_name }},</p>
        
        <p>Welcome to ACME Corporation! We're thrilled to have you join our innovative platform.</p>
        
        <p>As a new member, you'll have access to:</p>
        <ul>
            <li>Advanced analytics dashboard</li>
            <li>24/7 customer support</li>
            <li>Integration with 100+ tools</li>
            <li>Custom reporting features</li>
        </ul>
        
        <p>Get started by accessing your dashboard:</p>
        <a href="{{ app.request.schemeAndHttpHost }}/dashboard" class="cta-button">Access Dashboard</a>
        
        <p>Need help getting started? Check out our <a href="{{ app.request.schemeAndHttpHost }}/docs">documentation</a> or contact our support team.</p>
        
        <p>Best regards,<br>The ACME Team</p>
    </div>
    
    <div class="footer">
        <p>ACME Corporation | 123 Business St, Suite 100 | City, State 12345</p>
        <p>© {{ "now"|date("Y") }} ACME Corporation. All rights reserved.</p>
    </div>
</body>
</html>
```

## Email Service Examples

### Notification Service

```php
<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\TenantEmailTemplateService;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantMailerConfigurator $mailerConfigurator,
        private TenantEmailTemplateService $templateService,
    ) {}

    public function sendPasswordResetEmail(User $user, string $resetToken): void
    {
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        $resetUrl = sprintf('%s/reset-password?token=%s', 
            $_ENV['APP_URL'] ?? 'http://localhost', 
            $resetToken
        );

        $htmlContent = $this->templateService->renderTemplate('password_reset.html.twig', [
            'user_name' => $user->getName(),
            'reset_url' => $resetUrl,
            'expires_at' => new \DateTime('+1 hour'),
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $senderName, $fromAddress))
            ->to($user->getEmail())
            ->subject('Password Reset Request')
            ->html($htmlContent);

        $this->mailer->send($email);
    }

    public function sendOrderConfirmation(Order $order): void
    {
        $user = $order->getCustomer();
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        $htmlContent = $this->templateService->renderTemplate('order_confirmation.html.twig', [
            'user_name' => $user->getName(),
            'order' => $order,
            'order_items' => $order->getItems(),
            'total' => $order->getTotal(),
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $senderName, $fromAddress))
            ->to($user->getEmail())
            ->subject(sprintf('Order Confirmation #%s', $order->getOrderNumber()))
            ->html($htmlContent);

        $this->mailer->send($email);
    }

    public function sendBulkNewsletter(array $recipients, string $subject, string $content): void
    {
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        foreach ($recipients as $recipient) {
            $personalizedContent = $this->templateService->renderTemplate('newsletter.html.twig', [
                'user_name' => $recipient->getName(),
                'content' => $content,
                'unsubscribe_url' => sprintf('%s/unsubscribe?token=%s', 
                    $_ENV['APP_URL'] ?? 'http://localhost',
                    $recipient->getUnsubscribeToken()
                ),
            ]);

            $email = (new Email())
                ->from(sprintf('%s <%s>', $senderName, $fromAddress))
                ->to($recipient->getEmail())
                ->subject($subject)
                ->html($personalizedContent);

            $this->mailer->send($email);
        }
    }
}
```

### Transactional Email Service

```php
<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use App\Service\TenantEmailTemplateService;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

class TransactionalEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantMailerConfigurator $mailerConfigurator,
        private TenantEmailTemplateService $templateService,
    ) {}

    public function sendInvoice(Invoice $invoice): void
    {
        $customer = $invoice->getCustomer();
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        $htmlContent = $this->templateService->renderTemplate('invoice.html.twig', [
            'customer_name' => $customer->getName(),
            'invoice' => $invoice,
            'invoice_items' => $invoice->getItems(),
            'due_date' => $invoice->getDueDate(),
        ]);

        $email = (new Email())
            ->from(new Address($fromAddress, $senderName))
            ->to(new Address($customer->getEmail(), $customer->getName()))
            ->subject(sprintf('Invoice #%s', $invoice->getNumber()))
            ->html($htmlContent);

        // Attach PDF invoice if available
        if ($invoice->getPdfPath()) {
            $email->attachFromPath($invoice->getPdfPath(), sprintf('invoice_%s.pdf', $invoice->getNumber()));
        }

        $this->mailer->send($email);
    }

    public function sendPaymentConfirmation(Payment $payment): void
    {
        $customer = $payment->getCustomer();
        $fromAddress = $this->mailerConfigurator->getFromAddress();
        $senderName = $this->mailerConfigurator->getSenderName();

        $htmlContent = $this->templateService->renderTemplate('payment_confirmation.html.twig', [
            'customer_name' => $customer->getName(),
            'payment' => $payment,
            'amount' => $payment->getAmount(),
            'payment_method' => $payment->getPaymentMethod(),
            'transaction_id' => $payment->getTransactionId(),
        ]);

        $email = (new Email())
            ->from(new Address($fromAddress, $senderName))
            ->to(new Address($customer->getEmail(), $customer->getName()))
            ->subject('Payment Confirmation')
            ->html($htmlContent);

        $this->mailer->send($email);
    }
}
```

## Testing

### Unit Testing

```php
<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use App\Service\EmailService;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class EmailServiceTest extends TestCase
{
    public function testSendWelcomeEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailerConfigurator = $this->createMock(TenantMailerConfigurator::class);
        $tenantContext = $this->createMock(TenantContextInterface::class);

        $tenant = $this->createMockTenant('acme', 'ACME Corp');
        
        $tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $mailerConfigurator
            ->expects($this->once())
            ->method('getFromAddress')
            ->willReturn('noreply@acme.com');

        $mailerConfigurator
            ->expects($this->once())
            ->method('getSenderName')
            ->willReturn('ACME Corp');

        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return $email->getFrom()[0]->getAddress() === 'noreply@acme.com'
                    && $email->getTo()[0]->getAddress() === 'test@example.com'
                    && str_contains($email->getSubject(), 'ACME Corp');
            }));

        $emailService = new EmailService($mailer, $mailerConfigurator, $tenantContext);
        $emailService->sendWelcomeEmail('test@example.com', 'John Doe');
    }

    private function createMockTenant(string $slug, string $name): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getName')->willReturn($name);
        return $tenant;
    }
}
```

### Integration Testing

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\DataCollector\MessageDataCollector;
use App\Service\EmailService;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class EmailIntegrationTest extends KernelTestCase
{
    public function testTenantSpecificEmailSending(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Enable email collection for testing
        $container->get('mailer')->enableLogging();

        $emailService = $container->get(EmailService::class);
        $tenantContext = $container->get(TenantContextInterface::class);

        // Set tenant context
        $tenant = $this->createTestTenant();
        $tenantContext->setTenant($tenant);

        // Send email
        $emailService->sendWelcomeEmail('test@example.com', 'John Doe');

        // Verify email was sent with correct configuration
        $collector = $container->get(MessageDataCollector::class);
        $messages = $collector->getMessages();

        $this->assertCount(1, $messages);
        
        $message = $messages[0];
        $this->assertEquals('test@example.com', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($tenant->getName(), $message->getSubject());
    }
}
```

## Console Commands

### Test Email Configuration

```bash
# Test email configuration for a tenant
php bin/console tenant:email:test --tenant=acme --to=test@example.com

# Send test email with current configuration
php bin/console tenant:email:send-test --tenant=acme --to=admin@example.com --subject="Test Email"
```

### Configure Email Settings

```bash
# Set email configuration for a tenant
php bin/console tenant:email:configure --tenant=acme \
    --from=noreply@acme.com \
    --sender="ACME Corporation" \
    --dsn="smtp://smtp.acme.com:587"
```

## Troubleshooting

### Common Issues

1. **Email Not Sending**: Check tenant context and mailer configuration
2. **Wrong Sender Information**: Verify tenant settings are configured
3. **Template Not Found**: Ensure template exists in correct directory
4. **SMTP Errors**: Validate SMTP credentials and settings

### Debug Commands

```bash
# Check mailer configuration
php bin/console debug:config zhortein_multi_tenant mailer

# Test SMTP connection
php bin/console tenant:email:test-connection --tenant=acme

# View current email settings
php bin/console tenant:settings:list --tenant=acme --keys=email_,mailer_,smtp_
```

### Logging

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        mailer:
            type: stream
            path: '%kernel.logs_dir%/mailer.log'
            level: debug
            channels: ['mailer']
```

## Best Practices

1. **Always Set Fallbacks**: Configure fallback email settings
2. **Validate Email Addresses**: Use proper validation for email inputs
3. **Use Templates**: Leverage Twig templates for consistent formatting
4. **Test Email Sending**: Implement proper testing for email functionality
5. **Monitor Email Delivery**: Set up monitoring for email delivery rates
6. **Handle Failures Gracefully**: Implement proper error handling
7. **Respect Privacy**: Follow email privacy and unsubscribe regulations