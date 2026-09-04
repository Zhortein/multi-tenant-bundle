# Tenant-Aware Messenger

The Zhortein Multi-Tenant Bundle propagates and validates tenant context through Symfony Messenger. Applications can either keep the historical transport-per-tenant selection or let Symfony apply its native message routing.

> 📖 **Navigation**: [← Mailer](mailer.md) | [Back to Documentation Index](index.md) | [Storage →](storage.md)

## Overview

The tenant-aware messenger system consists of several components:

- **TenantMessengerConfigurator**: Manages tenant-specific messenger settings
- **TenantMessengerTransportResolver**: Middleware that optionally selects tenant-specific transports
- **TenantStamp**: Carries tenant ID with messages for async processing
- **TenantSendingMiddleware**: Automatically attaches tenant context to outgoing messages
- **TenantWorkerMiddleware**: Restores tenant context when processing messages in workers
- **TenantMessengerTransportFactory**: Creates tenant-specific transport instances

## Tenant Propagation

Every application message must implement exactly one public marker interface:

- `TenantAwareMessageInterface` for business messages requiring a tenant;
- `GlobalMessageInterface` for explicitly global messages.

Third-party messages must be wrapped in a classified application message. Unclassified messages, doubly classified messages, global messages carrying a `TenantStamp`, and tenant-aware messages sent without context are rejected before downstream middleware. Existing identical stamps are retained; contradictory stamps are rejected.

The bundle automatically propagates tenant context across asynchronous message processing:

1. **Sending Phase**: When dispatching a message, `TenantSendingMiddleware` automatically attaches a `TenantStamp` containing the current tenant ID
2. **Worker Phase**: `TenantWorkerMiddleware` first resets stale process state, then reads the `TenantStamp`, installs the message tenant, and configures database state
3. **Cleanup**: After success, invalid metadata, handler exception, retry, or redelivery, all tenant state is reset to `NONE` in `finally`

The worker never saves or restores a tenant that existed before consumption.
A global message also starts and ends at `NONE`; its explicit global Doctrine
scope exists only around the handler. Stopping and resuming a Worker does not
change this contract.

On receipt, a tenant-aware message requires one or more mutually consistent, non-empty `TenantStamp` values and a tenant resolvable by the registry. Missing metadata throws `MissingTenantStampException`; an unavailable tenant throws `UnknownTenantException`. The handler is never called on these failures. `UnknownTenantException` is non-retryable unless the application knows that registry availability is transient; transport or registry infrastructure failures may be retryable under application policy.

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
        routing_strategy: 'symfony_routing'
        add_tenant_headers: true
        fallback_dsn: 'sync://'
        fallback_bus: 'messenger.bus.default'
```

### Configuration Options

- `enabled`: Enable/disable tenant-aware messenger functionality
- `routing_strategy`: `tenant_transport` (default) or `symfony_routing`
- `default_transport`: Default transport when no tenant-specific mapping exists in `tenant_transport` mode
- `add_tenant_headers`: Add tenant information to message stamps/headers
- `tenant_transport_map`: Mapping of tenant slugs to transport names in `tenant_transport` mode
- `fallback_dsn`: Default messenger DSN when tenant has no specific configuration
- `fallback_bus`: Default messenger bus when tenant has no specific configuration

## Routing strategies

`MessengerRoutingStrategy::TENANT_TRANSPORT` (`tenant_transport`) is the backward-compatible default. For a tenant-aware dispatch with an active tenant, the resolver keeps an existing `TransportNamesStamp` intact; otherwise, it adds one from `tenant_transport_map`, falling back to `default_transport`.

```yaml
zhortein_multi_tenant:
    messenger:
        routing_strategy: tenant_transport
        default_transport: async
        tenant_transport_map:
            acme: acme_transport
```

`MessengerRoutingStrategy::SYMFONY_ROUTING` (`symfony_routing`) never adds, replaces, or removes a `TransportNamesStamp`. The bundle does not inspect `framework.messenger.routing`, `#[AsMessage]`, or Symfony's sender locator. Symfony therefore applies its normal order: an explicit stamp first, configured routing before `#[AsMessage]`, then no sender when nothing matches. Transport aliases and unknown aliases are validated by Symfony.

```yaml
zhortein_multi_tenant:
    messenger:
        routing_strategy: symfony_routing
        # These values do not affect envelopes in native mode.
        default_transport: async
        tenant_transport_map:
            acme: acme_transport
```

There is no fallback to `default_transport` in `symfony_routing` mode. A message with no native route and a handler can be handled synchronously by Symfony. Applications that require asynchronous delivery must define and test exhaustive Symfony routes for every such message.

Both strategies retain the same fail-closed classification, tenant stamping, active-tenant consistency, receive-side registry validation, handler rejection, and cleanup rules. The bundle prepends these protections to every declared Messenger bus.

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
                    # Tenant middleware is automatically registered
        
        transports:
            # Default transport
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
            
            notifications: 'doctrine://default?queue_name=notifications'
            documents: 'doctrine://default?queue_name=documents'
        
        routing:
            'App\Message\SendNotification': notifications
            'App\Message\GenerateDocument': documents
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

## Middleware Registration

The tenant propagation middleware is automatically registered when the bundle is enabled. The middleware stack includes:

1. **TenantWorkerMiddleware** (Priority: 200) - Restores tenant context for received messages
2. **TenantSendingMiddleware** (Priority: 150) - Attaches tenant context to outgoing messages
3. **TenantMessengerTransportResolver** (Priority: 100) - Applies the configured routing strategy without weakening tenant propagation

### Manual Middleware Configuration

If you need to customize middleware registration, you can configure it manually:

```yaml
# config/services.yaml
services:
    Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware:
        tags:
            - { name: messenger.middleware, priority: 150 }
    
    Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware:
        tags:
            - { name: messenger.middleware, priority: 200 }
```

## Usage

### Basic Message Dispatching

Messages are tagged with tenant context and routed according to the selected strategy:

```php
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SendEmailMessage;

public function sendMessage(MessageBusInterface $bus): void
{
    $message = new SendEmailMessage('user@example.com', 'Welcome!');
    
    // Message will be automatically:
    // 1. Tagged with current tenant ID (TenantStamp)
    // 2. Routed by the configured bundle strategy or Symfony routing
    // 3. Processed with tenant context restored in worker
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
        // Tenant context is automatically restored by TenantWorkerMiddleware
        // You can access it directly from the context
        if ($this->tenantContext->hasTenant()) {
            $tenant = $this->tenantContext->getTenant();
            $this->processForTenant($message, $tenant);
        }
        
        // Or get tenant ID from the stamp if needed
        /** @var TenantStamp|null $tenantStamp */
        $tenantStamp = $envelope->last(TenantStamp::class);
        if ($tenantStamp) {
            $tenantId = $tenantStamp->getTenantId();
            // Process with tenant ID
        }
    }
    
    private function processForTenant(TenantAwareMessage $message, object $tenant): void
    {
        // Your tenant-specific processing logic
        echo "Processing message for tenant: {$tenant->getName()} ({$tenant->getSlug()})";
        echo "Data: " . $message->getData();
        
        // Database queries will automatically be filtered by tenant
        // thanks to the restored tenant context
    }
}
```

### Automatic Tenant Propagation

The bundle provides automatic tenant context propagation through two middleware components:

#### TenantSendingMiddleware

Automatically attaches tenant context to outgoing messages:

```php
// When you dispatch a message with tenant context active:
$tenantContext->setTenant($tenant); // Tenant ID: "123"
$bus->dispatch(new MyMessage());

// The middleware automatically:
// 1. Detects current tenant context
// 2. Attaches TenantStamp with tenant ID "123"
// 3. Message is queued with tenant information
```

#### TenantWorkerMiddleware

Automatically restores tenant context when processing messages:

```php
// When a worker processes the message:
// 1. Reads TenantStamp from message envelope
// 2. Looks up tenant by ID in TenantRegistry
// 3. Sets tenant in TenantContext
// 4. Configures database session (RLS, etc.)
// 5. Processes message with full tenant context
// 6. Clears tenant context after processing
```

#### Safety Features

- **Exact classification**: Every application message implements exactly one of `TenantAwareMessageInterface` or `GlobalMessageInterface`; unclassified and doubly classified messages are rejected
- **Fail-closed sending**: A tenant-aware message without an active tenant context is rejected before downstream middleware
- **Fail-closed consumption**: A tenant-aware message without a `TenantStamp`, or whose tenant is unknown, is rejected before its handler
- **Explicit global messages**: A global message is accepted only without a `TenantStamp`; stamped global messages are rejected
- **Exception safety**: Tenant context is always cleared, even if message processing fails
- **Existing stamp validation**: Identical stamps are retained; contradictory stamps are rejected

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

In `tenant_transport` mode, `TenantMessengerTransportResolver` automatically:

1. **Routes messages** to tenant-specific transports based on configuration
2. **Adds tenant stamps** to preserve tenant context in async processing
3. **Handles fallbacks** when no tenant-specific transport is configured

In `symfony_routing` mode it performs none of those transport-selection steps. It only preserves the shared tenant-propagation behavior and delegates unchanged to the next middleware.

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

The transport resolver runs with priority 100. Symfony's sender middleware sees either the historical explicit transport stamp or the untouched envelope used for native routing.

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
        $tenantStamp = new TenantStamp('123'); // Tenant ID
        $envelope = new Envelope($message, [$tenantStamp]);
        
        // Mock tenant context to return the tenant
        $tenant = $this->createMockTenant('123', 'acme');
        $this->tenantContext->method('hasTenant')->willReturn(true);
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        
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
        dump('Tenant ID:', $tenantStamp->getTenantId());
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
        // Tenant context is automatically restored by TenantWorkerMiddleware
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException('Tenant context required for email processing');
        }

        // Send tenant-aware email - tenant context is already set
        $this->mailer->sendTemplatedEmail(
            $message->getTo(),
            $message->getSubject(),
            $message->getTemplate(),
            $message->getContext()
        );
        
        // Database queries in the mailer will be automatically
        // filtered by tenant thanks to the restored context
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
    // Message will be automatically:
    // 1. Tagged with current tenant ID (TenantStamp)
    // 2. Routed to tenant-specific transport
    // 3. Processed with tenant context fully restored in worker
    // 4. Database session configured for tenant isolation
}
```

See the [Messenger Usage Examples](examples/messenger-usage.md) for more practical implementation examples.
