# Tenant-Aware Messenger Usage Examples

## Basic Configuration

### 1. Configure Tenant Settings

```php
// In your controller or service
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantMessengerConfigurationController
{
    public function __construct(
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureMessenger(): void
    {
        // Set tenant-specific transport DSN
        $this->settingsManager->set('messenger_transport_dsn', 'redis://localhost:6379/messages');
        
        // Set bus name (optional)
        $this->settingsManager->set('messenger_bus', 'tenant.bus');
        
        // Set message delay in seconds (optional)
        $this->settingsManager->set('messenger_delay', 30);
        
        // Set retry configuration
        $this->settingsManager->set('messenger_retry_max', 3);
        $this->settingsManager->set('messenger_retry_delay', 1000);
    }
}
```

### 2. Service Configuration

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
            query.bus:
                middleware:
                    - validation
            tenant.bus:
                middleware:
                    - validation
                    - doctrine_transaction

        transports:
            # Default transport
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
                    max_delay: 0

            # Tenant-specific transport (will be dynamically configured)
            tenant_async:
                dsn: 'sync://' # Fallback, will be overridden by tenant settings
                retry_strategy:
                    max_retries: 3

        routing:
            'App\Message\TenantSpecificMessage': tenant_async
            'App\Message\GlobalMessage': async

# config/services.yaml
services:
    Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportFactory:
        arguments:
            $factories: !tagged_iterator messenger.transport_factory
        tags: ['messenger.transport_factory']
```

## Usage Examples

### 1. Basic Message Dispatching

```php
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\TenantNotificationMessage;

class NotificationService
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    public function sendTenantNotification(int $userId, string $message): void
    {
        $this->messageBus->dispatch(
            new TenantNotificationMessage($userId, $message)
        );
    }
}
```

### 2. Tenant-Specific Message

```php
// src/Message/TenantNotificationMessage.php
class TenantNotificationMessage
{
    public function __construct(
        private int $userId,
        private string $message,
        private ?string $tenantId = null
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}

// src/MessageHandler/TenantNotificationHandler.php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsMessageHandler]
class TenantNotificationHandler
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private NotificationService $notificationService
    ) {}

    public function __invoke(TenantNotificationMessage $message): void
    {
        // The tenant context should already be set by the transport
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for message processing');
        }

        $this->notificationService->sendNotification(
            $tenant,
            $message->getUserId(),
            $message->getMessage()
        );
    }
}
```

### 3. Advanced Configuration with Multiple Transports

```php
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantMessengerService
{
    public function __construct(
        private TenantMessengerConfigurator $configurator,
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureTenantTransports(): void
    {
        // Configure different transport types based on tenant needs
        $tenantType = $this->settingsManager->get('tenant_type', 'standard');

        match ($tenantType) {
            'premium' => $this->configurePremiumTransport(),
            'enterprise' => $this->configureEnterpriseTransport(),
            default => $this->configureStandardTransport(),
        };
    }

    private function configurePremiumTransport(): void
    {
        // Redis with higher priority
        $this->settingsManager->set('messenger_transport_dsn', 'redis://localhost:6379/premium');
        $this->settingsManager->set('messenger_delay', 0); // Immediate processing
        $this->settingsManager->set('messenger_retry_max', 5);
    }

    private function configureEnterpriseTransport(): void
    {
        // RabbitMQ with dedicated queue
        $this->settingsManager->set('messenger_transport_dsn', 'amqp://guest:guest@localhost:5672/%2f/enterprise');
        $this->settingsManager->set('messenger_delay', 0);
        $this->settingsManager->set('messenger_retry_max', 10);
    }

    private function configureStandardTransport(): void
    {
        // Doctrine transport for standard tenants
        $this->settingsManager->set('messenger_transport_dsn', 'doctrine://default');
        $this->settingsManager->set('messenger_delay', 60); // 1 minute delay
        $this->settingsManager->set('messenger_retry_max', 3);
    }

    public function getTransportConfiguration(): array
    {
        return [
            'dsn' => $this->configurator->getTransportDsn(),
            'bus' => $this->configurator->getBusName(),
            'delay' => $this->configurator->getDelay(),
            'retry_max' => $this->configurator->getRetryMax(),
            'retry_delay' => $this->configurator->getRetryDelay(),
        ];
    }
}
```

## Supported Transport Types

### 1. Synchronous Transport
```php
$this->settingsManager->set('messenger_transport_dsn', 'sync://');
```

### 2. Doctrine Transport
```php
$this->settingsManager->set('messenger_transport_dsn', 'doctrine://default');
// With custom table
$this->settingsManager->set('messenger_transport_dsn', 'doctrine://default?table_name=tenant_messages');
```

### 3. Redis Transport
```php
$this->settingsManager->set('messenger_transport_dsn', 'redis://localhost:6379/messages');
// With authentication
$this->settingsManager->set('messenger_transport_dsn', 'redis://user:pass@localhost:6379/messages');
```

### 4. RabbitMQ Transport
```php
$this->settingsManager->set('messenger_transport_dsn', 'amqp://guest:guest@localhost:5672/%2f/messages');
// With exchange and routing key
$this->settingsManager->set('messenger_transport_dsn', 'amqp://localhost?exchange[name]=tenant_exchange&queues[messages][routing_keys][0]=tenant.#');
```

### 5. Amazon SQS Transport
```php
$this->settingsManager->set('messenger_transport_dsn', 'sqs://ACCESS_KEY:SECRET_KEY@default/queue-name?region=us-east-1');
```

## Console Commands

### Configure Tenant Messenger Settings

```bash
# Set transport DSN
php bin/console tenant:settings:set tenant-slug messenger_transport_dsn "redis://localhost:6379/tenant_messages"

# Set bus name
php bin/console tenant:settings:set tenant-slug messenger_bus "tenant.bus"

# Set delay (in seconds)
php bin/console tenant:settings:set tenant-slug messenger_delay 30

# Set retry configuration
php bin/console tenant:settings:set tenant-slug messenger_retry_max 5
php bin/console tenant:settings:set tenant-slug messenger_retry_delay 2000

# View current settings
php bin/console tenant:settings:get tenant-slug messenger_transport_dsn
```

### Process Messages for Specific Tenant

```bash
# Process messages with tenant context
php bin/console messenger:consume tenant_async --limit=10

# Process with specific tenant context (if needed)
TENANT_SLUG=tenant-slug php bin/console messenger:consume tenant_async
```

## Testing

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantMessengerConfiguratorTest extends TestCase
{
    public function testGetTransportDsn(): void
    {
        $settingsManager = $this->createMock(TenantSettingsManager::class);
        $settingsManager->expects($this->once())
            ->method('get')
            ->with('messenger_transport_dsn', 'sync://')
            ->willReturn('redis://localhost:6379/tenant');

        $configurator = new TenantMessengerConfigurator($settingsManager);
        
        $this->assertEquals('redis://localhost:6379/tenant', $configurator->getTransportDsn());
    }

    public function testGetTransportDsnWithFallback(): void
    {
        $settingsManager = $this->createMock(TenantSettingsManager::class);
        $settingsManager->expects($this->once())
            ->method('get')
            ->with('messenger_transport_dsn', 'doctrine://default')
            ->willReturn(null);

        $configurator = new TenantMessengerConfigurator($settingsManager);
        
        $this->assertEquals('doctrine://default', $configurator->getTransportDsn('doctrine://default'));
    }
}
```

### Integration Test

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\TenantTestMessage;

class TenantMessengerIntegrationTest extends KernelTestCase
{
    public function testMessageDispatchWithTenantContext(): void
    {
        self::bootKernel();
        
        $container = static::getContainer();
        $messageBus = $container->get(MessageBusInterface::class);
        $tenantContext = $container->get(TenantContextInterface::class);
        
        // Set tenant context
        $tenant = $this->createTestTenant();
        $tenantContext->setTenant($tenant);
        
        // Dispatch message
        $message = new TenantTestMessage('test data');
        $messageBus->dispatch($message);
        
        // Assert message was processed with correct tenant context
        $this->assertTrue(true); // Add specific assertions based on your implementation
    }
}
```

## Best Practices

### 1. Message Design

```php
// Good: Include tenant information in message
class TenantAwareMessage
{
    public function __construct(
        private string $data,
        private ?string $tenantId = null
    ) {}
}

// Better: Use tenant context service
#[AsMessageHandler]
class MessageHandler
{
    public function __construct(
        private TenantContextInterface $tenantContext
    ) {}

    public function __invoke(TenantAwareMessage $message): void
    {
        $tenant = $this->tenantContext->getTenant();
        // Process with tenant context
    }
}
```

### 2. Error Handling

```php
#[AsMessageHandler]
class RobustMessageHandler
{
    public function __invoke(TenantMessage $message): void
    {
        try {
            $tenant = $this->tenantContext->getTenant();
            if (!$tenant) {
                throw new \RuntimeException('No tenant context available');
            }
            
            // Process message
            $this->processMessage($message, $tenant);
            
        } catch (\Exception $e) {
            // Log error with tenant context
            $this->logger->error('Message processing failed', [
                'tenant_id' => $tenant?->getId(),
                'message_class' => get_class($message),
                'error' => $e->getMessage(),
            ]);
            
            throw $e; // Re-throw for retry mechanism
        }
    }
}
```

### 3. Performance Optimization

```php
// Use different transports based on message priority
class TenantMessageRouter
{
    public function routeMessage(object $message): string
    {
        return match (true) {
            $message instanceof HighPriorityMessage => 'redis://localhost:6379/high_priority',
            $message instanceof BulkMessage => 'doctrine://default?table_name=bulk_messages',
            default => 'redis://localhost:6379/default',
        };
    }
}
```

## Configuration Reference

### Available Tenant Settings

| Setting Key | Description | Example Value |
|-------------|-------------|---------------|
| `messenger_transport_dsn` | Transport DSN | `redis://localhost:6379/messages` |
| `messenger_bus` | Bus name | `tenant.bus` |
| `messenger_delay` | Message delay (seconds) | `30` |
| `messenger_retry_max` | Maximum retries | `5` |
| `messenger_retry_delay` | Retry delay (milliseconds) | `2000` |

### Transport DSN Examples

| Transport | DSN Format |
|-----------|------------|
| Sync | `sync://` |
| Doctrine | `doctrine://default?table_name=messages` |
| Redis | `redis://localhost:6379/messages` |
| RabbitMQ | `amqp://guest:guest@localhost:5672/%2f/messages` |
| Amazon SQS | `sqs://ACCESS_KEY:SECRET_KEY@default/queue-name?region=us-east-1` |