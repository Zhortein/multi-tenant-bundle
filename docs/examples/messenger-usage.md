# Tenant-Aware Messenger Usage Examples

This document provides practical examples of using the tenant-aware messenger functionality with automatic transport routing and tenant context preservation.

> 📖 **Navigation**: [← Mailer Usage](mailer-usage.md) | [Back to Documentation Index](../index.md) | [Storage Usage →](storage-usage.md)

## Basic Configuration

### 1. Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    messenger:
        enabled: true
        default_transport: 'async'
        add_tenant_headers: true
        tenant_transport_map:
            acme: 'acme_transport'
            bio: 'bio_transport'
            startup: 'startup_transport'
        fallback_dsn: 'sync://'
        fallback_bus: 'messenger.bus.default'
```

### 2. Symfony Messenger Configuration

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        
        buses:
            command.bus:
                middleware:
                    - validation
                    - doctrine_transaction
        
        transports:
            # Default transport
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
            
            # Tenant-specific transports
            acme_transport:
                dsn: 'redis://localhost:6379/acme_messages'
                retry_strategy:
                    max_retries: 5
                    delay: 1000
                    
            bio_transport:
                dsn: 'amqp://guest:guest@localhost:5672/bio_vhost/bio_messages'
                retry_strategy:
                    max_retries: 3
                    
            startup_transport:
                dsn: 'doctrine://default?queue_name=startup_messages'
        
        routing:
            'App\Message\*': async
```

### 3. Configure Tenant Settings

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantMessengerConfigurationController
{
    public function __construct(
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureMessenger(): void
    {
        // Transport configuration
        $this->settingsManager->set('messenger_transport_dsn', 'redis://localhost:6379/tenant_messages');
        $this->settingsManager->set('messenger_bus', 'command.bus');
        
        // Delay configuration (in milliseconds)
        $this->settingsManager->set('messenger_delay', 5000); // 5 seconds default
        $this->settingsManager->set('messenger_delay_email', 10000); // 10 seconds for email
        $this->settingsManager->set('messenger_delay_reports', 30000); // 30 seconds for reports
    }
}
```

## Usage Examples

### 1. Basic Message Dispatching

```php
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SendEmailMessage;

class NotificationService
{
    public function __construct(
        private MessageBusInterface $bus
    ) {}

    public function sendWelcomeEmail(string $userEmail, array $user): void
    {
        $message = new SendEmailMessage($userEmail, 'welcome', $user);
        
        // Message will be automatically:
        // 1. Routed to tenant-specific transport (e.g., acme_transport)
        // 2. Tagged with TenantStamp containing tenant info
        $this->bus->dispatch($message);
    }
}
```

### 2. Custom Message with Tenant Context

```php
namespace App\Message;

class ProcessTenantDataMessage
{
    public function __construct(
        private readonly string $dataType,
        private readonly array $data,
        private readonly ?string $tenantSlug = null,
    ) {}

    public function getDataType(): string
    {
        return $this->dataType;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTenantSlug(): ?string
    {
        return $this->tenantSlug;
    }
}
```

### 3. Message Handler with Tenant Context

```php
namespace App\MessageHandler;

use App\Message\ProcessTenantDataMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

#[AsMessageHandler]
class ProcessTenantDataHandler
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantRegistryInterface $tenantRegistry,
    ) {}

    public function __invoke(ProcessTenantDataMessage $message, Envelope $envelope): void
    {
        // Get tenant information from the stamp
        $tenantStamp = $envelope->last(TenantStamp::class);
        
        if ($tenantStamp) {
            // Set tenant context for the handler
            $tenant = $this->tenantRegistry->getBySlug($tenantStamp->getTenantSlug());
            $this->tenantContext->setTenant($tenant);
            
            // Process message with tenant context
            $this->processDataForTenant(
                $message->getDataType(),
                $message->getData(),
                $tenantStamp->getTenantSlug(),
                $tenantStamp->getTenantName()
            );
        } else {
            // Handle messages without tenant context
            $this->processDataWithoutTenant($message);
        }
    }
    
    private function processDataForTenant(string $type, array $data, string $slug, string $name): void
    {
        echo "Processing {$type} data for tenant: {$name} ({$slug})\n";
        
        // Your tenant-specific processing logic here
        switch ($type) {
            case 'user_data':
                $this->processUserData($data);
                break;
            case 'analytics':
                $this->processAnalytics($data);
                break;
            default:
                $this->processGenericData($data);
        }
    }
    
    private function processDataWithoutTenant(ProcessTenantDataMessage $message): void
    {
        // Handle global/system messages
        echo "Processing system-wide data: {$message->getDataType()}\n";
    }
}
```

### 4. Email Processing with Tenant Context

```php
namespace App\Message;

class SendTenantEmailMessage
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $template,
        private readonly array $context = [],
    ) {}

    // Getters...
    public function getTo(): string { return $this->to; }
    public function getSubject(): string { return $this->subject; }
    public function getTemplate(): string { return $this->template; }
    public function getContext(): array { return $this->context; }
}

// Handler
namespace App\MessageHandler;

use App\Message\SendTenantEmailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

#[AsMessageHandler]
class SendTenantEmailHandler
{
    public function __construct(
        private readonly TenantAwareMailer $mailer,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantRegistryInterface $tenantRegistry,
    ) {}

    public function __invoke(SendTenantEmailMessage $message, Envelope $envelope): void
    {
        // Get tenant from stamp
        $tenantStamp = $envelope->last(TenantStamp::class);
        if (!$tenantStamp) {
            throw new \RuntimeException('Tenant context required for email processing');
        }

        // Set tenant context for the handler
        $tenant = $this->tenantRegistry->getBySlug($tenantStamp->getTenantSlug());
        $this->tenantContext->setTenant($tenant);

        // Send tenant-aware email
        $this->mailer->sendTemplatedEmail(
            $message->getTo(),
            $message->getSubject(),
            $message->getTemplate(),
            $message->getContext()
        );
        
        echo "Email sent for tenant: {$tenantStamp->getTenantName()}\n";
    }
}
```

### 5. Delayed Message Processing

```php
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

class ScheduledTaskService
{
    public function __construct(
        private MessageBusInterface $bus,
        private TenantMessengerConfigurator $configurator,
    ) {}

    public function scheduleReport(array $reportData): void
    {
        // Get tenant-specific delay
        $delay = $this->configurator->getDelay('reports', 30000); // 30 second default
        
        $message = new GenerateReportMessage($reportData);
        
        $this->bus->dispatch($message, [
            new DelayStamp($delay)
        ]);
    }

    public function scheduleEmailBatch(array $emails): void
    {
        $emailDelay = $this->configurator->getDelay('email', 5000);
        
        foreach ($emails as $index => $emailData) {
            $message = new SendTenantEmailMessage(
                $emailData['to'],
                $emailData['subject'],
                $emailData['template'],
                $emailData['context']
            );
            
            // Stagger emails to avoid overwhelming the system
            $delay = $emailDelay + ($index * 1000); // Add 1 second per email
            
            $this->bus->dispatch($message, [
                new DelayStamp($delay)
            ]);
        }
    }
}
```

### 6. Custom Transport Resolution

```php
namespace App\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class PriorityTenantTransportMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly array $priorityTenants = ['acme', 'enterprise'],
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenant = $this->tenantContext->getTenant();
        
        if ($tenant && !$envelope->last(TransportNamesStamp::class)) {
            $transportName = $this->resolvePriorityTransport($tenant->getSlug(), $envelope);
            $envelope = $envelope->with(new TransportNamesStamp([$transportName]));
        }
        
        return $stack->next()->handle($envelope, $stack);
    }
    
    private function resolvePriorityTransport(string $tenantSlug, Envelope $envelope): string
    {
        $messageClass = get_class($envelope->getMessage());
        $isPriorityTenant = in_array($tenantSlug, $this->priorityTenants);
        
        return match (true) {
            $isPriorityTenant && str_contains($messageClass, 'Email') => 'priority_email',
            $isPriorityTenant => 'priority_async',
            str_contains($messageClass, 'Email') => $tenantSlug . '_email',
            default => $tenantSlug . '_async',
        };
    }
}
```

## Advanced Configuration

### 1. Tenant-Specific Routing

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            # Route different message types to different transports
            'App\Message\EmailMessage':
                - 'acme_email'      # For acme tenant
                - 'bio_email'       # For bio tenant  
                - 'email_default'   # Default fallback
                
            'App\Message\ReportMessage':
                - 'acme_reports'
                - 'bio_reports'
                - 'reports_default'
                
            'App\Message\PriorityMessage':
                - 'priority_queue'  # High-priority messages
```

### 2. Environment-Specific Transport Configuration

```yaml
# config/packages/prod/messenger.yaml
framework:
    messenger:
        transports:
            acme_transport:
                dsn: 'redis://redis-cluster:6379/acme_prod'
                options:
                    stream_max_entries: 10000
                retry_strategy:
                    max_retries: 5
                    delay: 2000
                    
            bio_transport:
                dsn: 'amqp://rabbitmq:5672/bio_prod'
                options:
                    exchange:
                        name: 'bio_exchange'
                        type: 'direct'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
```

## Testing

### 1. Unit Testing Message Handlers

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

class ProcessTenantDataHandlerTest extends TestCase
{
    public function testHandleMessageWithTenantContext(): void
    {
        $message = new ProcessTenantDataMessage('user_data', ['id' => 123]);
        $tenantStamp = new TenantStamp('acme', 'Acme Corporation');
        $envelope = new Envelope($message, [$tenantStamp]);
        
        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        
        $handler = new ProcessTenantDataHandler($tenantContext, $tenantRegistry);
        $handler($message, $envelope);
        
        // Assert message was processed correctly
        $this->assertTrue(true); // Add your assertions
    }
}
```

### 2. Integration Testing

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class MessengerIntegrationTest extends KernelTestCase
{
    public function testTenantMessageRouting(): void
    {
        self::bootKernel();
        
        // Set tenant context
        $tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $tenant = $this->createTenant('acme', 'Acme Corp');
        $tenantContext->setTenant($tenant);
        
        // Dispatch message
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $message = new ProcessTenantDataMessage('test', ['data' => 'value']);
        $bus->dispatch($message);
        
        // Verify message was routed to correct transport
        // and tenant stamp was added
        $this->assertTrue(true); // Add your verification logic
    }
}
```

## Monitoring and Debugging

### 1. Debug Message Stamps

```php
public function debugMessageStamps(Envelope $envelope): void
{
    echo "=== Message Stamps Debug ===\n";
    
    foreach ($envelope->all() as $stampClass => $stamps) {
        echo "Stamp Class: {$stampClass}\n";
        foreach ($stamps as $stamp) {
            if ($stamp instanceof TenantStamp) {
                echo "  Tenant: {$stamp->getTenantSlug()} ({$stamp->getTenantName()})\n";
            } elseif ($stamp instanceof TransportNamesStamp) {
                echo "  Transports: " . implode(', ', $stamp->getTransportNames()) . "\n";
            } else {
                echo "  Stamp: " . get_class($stamp) . "\n";
            }
        }
    }
    echo "===========================\n";
}
```

### 2. Monitor Queue Lengths

```php
use Symfony\Component\Messenger\Transport\TransportInterface;

class MessengerMonitoringService
{
    public function __construct(
        private TransportInterface $acmeTransport,
        private TransportInterface $bioTransport,
    ) {}

    public function getQueueStats(): array
    {
        return [
            'acme' => [
                'waiting' => $this->acmeTransport->getMessageCount(),
                'transport' => 'acme_transport',
            ],
            'bio' => [
                'waiting' => $this->bioTransport->getMessageCount(),
                'transport' => 'bio_transport',
            ],
        ];
    }
}
```

## Best Practices

1. **Transport Isolation**: Use separate transports for different tenants to ensure isolation
2. **Fallback Configuration**: Always provide fallback settings for reliability
3. **Stamp Usage**: Use TenantStamp to maintain tenant context in async processing
4. **Error Handling**: Handle missing tenant context gracefully in message handlers
5. **Testing**: Test message routing with different tenant configurations
6. **Performance**: Consider transport performance characteristics for each tenant
7. **Monitoring**: Monitor queue lengths and processing times per tenant
8. **Security**: Ensure tenant isolation is maintained throughout message processing
9. **Retry Strategy**: Configure appropriate retry strategies per tenant/transport
10. **Resource Management**: Monitor memory and CPU usage for high-volume tenants