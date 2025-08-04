# Tenant-Aware Messenger

The Zhortein Multi-Tenant Bundle provides comprehensive tenant-aware message queue functionality through its messenger integration. This allows you to route messages to tenant-specific transports and maintain tenant context throughout asynchronous processing.

## Overview

The tenant-aware messenger system consists of several components:

- **TenantMessengerConfigurator**: Manages tenant-specific messenger settings
- **TenantMessengerTransportResolver**: Middleware that routes messages to tenant-specific transports
- **TenantStamp**: Carries tenant information with messages for async processing
- **TenantMessengerTransportFactory**: Creates tenant-specific transport instances

## Requirements

The messenger functionality requires the following package:

```bash
composer require symfony/messenger
```

## Configuration

Enable the messenger integration in your bundle configuration:

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

### Configuration Options

- `enabled`: Enable/disable tenant-aware messenger functionality
- `default_transport`: Default transport when no tenant-specific mapping exists
- `add_tenant_headers`: Add tenant information to message stamps/headers
- `tenant_transport_map`: Mapping of tenant slugs to transport names
- `fallback_dsn`: Default messenger DSN when tenant has no specific configuration
- `fallback_bus`: Default messenger bus when tenant has no specific configuration

## Symfony Messenger Configuration

Configure your transports in the standard Symfony Messenger configuration:

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
                    
            bio_transport:
                dsn: 'amqp://guest:guest@localhost:5672/bio_vhost/bio_messages'
                
            startup_transport:
                dsn: 'doctrine://default?queue_name=startup_messages'
        
        routing:
            # Route messages to appropriate transports
            'App\Message\*': async
```

## Tenant Settings

Configure messenger settings per tenant using the tenant settings system:

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

public function configureMessenger(TenantSettingsManager $settings): void
{
    // Messenger configuration
    $settings->set('messenger_transport_dsn', 'redis://localhost:6379/tenant_messages');
    $settings->set('messenger_bus', 'command.bus');
    $settings->set('messenger_delay', 5000); // 5 seconds delay
    $settings->set('messenger_delay_email', 10000); // 10 seconds for email transport
}
```

### Available Settings

| Setting Key | Description | Example |
|-------------|-------------|---------|
| `messenger_transport_dsn` | Transport DSN | `redis://localhost:6379/messages` |
| `messenger_bus` | Bus name | `command.bus` |
| `messenger_delay` | Default delay in milliseconds | `5000` |
| `messenger_delay_{transport}` | Transport-specific delay | `messenger_delay_email: 10000` |

## Usage

### Basic Message Dispatching

Messages are automatically routed to tenant-specific transports:

```php
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SendEmailMessage;

public function sendMessage(MessageBusInterface $bus): void
{
    $message = new SendEmailMessage('user@example.com', 'Welcome!');
    
    // Message will be automatically routed to tenant-specific transport
    // and tagged with tenant information
    $bus->dispatch($message);
}
```

### Custom Message with Tenant Context

Create messages that are tenant-aware:

```php
namespace App\Message;

class TenantAwareMessage
{
    public function __construct(
        private readonly string $tenantSlug,
        private readonly string $data,
    ) {
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    public function getData(): string
    {
        return $this->data;
    }
}
```

### Message Handlers with Tenant Context

Access tenant information in your message handlers:

```php
namespace App\MessageHandler;

use App\Message\TenantAwareMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

#[AsMessageHandler]
class TenantAwareMessageHandler
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function __invoke(TenantAwareMessage $message, Envelope $envelope): void
    {
        // Get tenant information from the stamp
        /** @var TenantStamp|null $tenantStamp */
        $tenantStamp = $envelope->last(TenantStamp::class);
        
        if ($tenantStamp) {
            $tenantSlug = $tenantStamp->getTenantSlug();
            $tenantName = $tenantStamp->getTenantName();
            
            // Process message with tenant context
            $this->processForTenant($message, $tenantSlug, $tenantName);
        }
    }
    
    private function processForTenant(TenantAwareMessage $message, string $slug, string $name): void
    {
        // Your tenant-specific processing logic
        echo "Processing message for tenant: {$name} ({$slug})";
        echo "Data: " . $message->getData();
    }
}
```

### Manual Transport Resolution

You can manually resolve transport names for specific tenants:

```php
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

public function getTransportInfo(TenantMessengerConfigurator $configurator): array
{
    return [
        'dsn' => $configurator->getTransportDsn(),
        'bus' => $configurator->getBusName(),
        'delay' => $configurator->getDelay(),
        'emailDelay' => $configurator->getDelay('email'),
    ];
}
```

## Transport Resolver Middleware

The `TenantMessengerTransportResolver` middleware automatically:

1. **Routes messages** to tenant-specific transports based on configuration
2. **Adds tenant stamps** to preserve tenant context in async processing
3. **Handles fallbacks** when no tenant-specific transport is configured

### How It Works

```php
// When a message is dispatched:
$bus->dispatch(new MyMessage());

// The middleware:
// 1. Gets current tenant from TenantContext
// 2. Looks up transport name in tenant_transport_map
// 3. Adds TransportNamesStamp with resolved transport
// 4. Adds TenantStamp with tenant information
// 5. Passes message to next middleware
```

### Middleware Priority

The transport resolver runs with high priority (100) to ensure tenant routing happens early in the middleware stack.

## Advanced Usage

### Custom Transport Mapping

You can implement custom logic for transport resolution:

```php
namespace App\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class CustomTenantTransportMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenant = $this->tenantContext->getTenant();
        
        if ($tenant && !$envelope->last(TransportNamesStamp::class)) {
            // Custom logic for transport selection
            $transportName = $this->resolveCustomTransport($tenant, $envelope);
            $envelope = $envelope->with(new TransportNamesStamp([$transportName]));
        }
        
        return $stack->next()->handle($envelope, $stack);
    }
    
    private function resolveCustomTransport(object $tenant, Envelope $envelope): string
    {
        // Your custom transport resolution logic
        $messageClass = get_class($envelope->getMessage());
        
        return match ($messageClass) {
            'App\Message\EmailMessage' => $tenant->getSlug() . '_email',
            'App\Message\ReportMessage' => $tenant->getSlug() . '_reports',
            default => $tenant->getSlug() . '_default',
        };
    }
}
```

### Tenant-Specific Message Routing

Configure different routing rules per tenant:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            # High-priority messages for premium tenants
            'App\Message\PriorityMessage':
                - 'acme_priority'  # Premium tenant
                - 'async'          # Default for others
                
            # Email messages to dedicated email transports
            'App\Message\EmailMessage':
                - 'acme_email'
                - 'bio_email'
                - 'email_default'
```

### Delayed Message Processing

Configure tenant-specific delays:

```php
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

public function scheduleMessage(MessageBusInterface $bus, TenantMessengerConfigurator $configurator): void
{
    $delay = $configurator->getDelay('email', 5000); // 5 second default
    
    $bus->dispatch(
        new SendEmailMessage('user@example.com', 'Delayed message'),
        [new DelayStamp($delay)]
    );
}
```

## Testing

### Unit Testing

Test your message handlers with tenant context:

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

class MessageHandlerTest extends TestCase
{
    public function testTenantAwareHandler(): void
    {
        $message = new TenantAwareMessage('acme', 'test data');
        $tenantStamp = new TenantStamp('acme', 'Acme Corporation');
        $envelope = new Envelope($message, [$tenantStamp]);
        
        $handler = new TenantAwareMessageHandler($this->tenantContext);
        $handler($message, $envelope);
        
        // Assert message was processed correctly
    }
}
```

### Integration Testing

Test the complete message flow:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MessengerIntegrationTest extends KernelTestCase
{
    public function testTenantMessageRouting(): void
    {
        self::bootKernel();
        
        // Set tenant context
        $tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $tenantContext->setTenant($tenant);
        
        // Dispatch message
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new TestMessage());
        
        // Verify message was routed to correct transport
        // and tenant stamp was added
    }
}
```

## Troubleshooting

### Common Issues

1. **Messages not routed**: Check tenant_transport_map configuration
2. **Tenant context lost**: Ensure TenantStamp is properly added and read
3. **Transport not found**: Verify transport is defined in messenger.yaml
4. **Middleware not working**: Check middleware registration and priority

### Debug Information

Enable debug mode to see detailed messenger information:

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            channels: ["messenger"]
```

### Inspect Message Stamps

Debug message stamps in your handlers:

```php
public function __invoke(MyMessage $message, Envelope $envelope): void
{
    // Debug all stamps
    foreach ($envelope->all() as $stampClass => $stamps) {
        foreach ($stamps as $stamp) {
            dump($stampClass, $stamp);
        }
    }
    
    // Check for tenant stamp specifically
    $tenantStamp = $envelope->last(TenantStamp::class);
    if ($tenantStamp) {
        dump('Tenant:', $tenantStamp->getTenantSlug(), $tenantStamp->getTenantName());
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

## Examples

### Complete Example: Tenant-Aware Email Processing

```php
// Message
namespace App\Message;

class SendTenantEmailMessage
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $template,
        private readonly array $context = [],
    ) {
    }

    // Getters...
}

// Handler
namespace App\MessageHandler;

use App\Message\SendTenantEmailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

#[AsMessageHandler]
class SendTenantEmailHandler
{
    public function __construct(
        private readonly TenantAwareMailer $mailer,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

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
    }
}

// Usage
public function scheduleEmail(MessageBusInterface $bus): void
{
    $bus->dispatch(new SendTenantEmailMessage(
        'user@example.com',
        'Welcome!',
        'emails/welcome.html.twig',
        ['user' => $user]
    ));
    // Message will be automatically routed to tenant-specific transport
    // and processed with tenant context preserved
}
```

See the [Messenger Usage Examples](examples/messenger-usage.md) for more practical implementation examples.