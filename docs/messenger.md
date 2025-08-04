# Tenant-Aware Messenger

The tenant-aware messenger system enables tenant-specific message queue configuration and processing. Each tenant can have its own message transport, routing rules, and processing logic while maintaining tenant isolation throughout the message lifecycle.

## Overview

The messenger integration provides:

- **Tenant-specific transports**: Each tenant can use different message brokers
- **Tenant context preservation**: Tenant information is maintained across async processing
- **Fallback configuration**: Global defaults when tenant settings are not configured
- **Automatic routing**: Messages are routed to tenant-specific queues
- **Isolation**: Complete message isolation between tenants

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    messenger:
        enabled: true
        fallback_dsn: 'sync://'
        fallback_bus: 'messenger.bus.default'
```

### Symfony Messenger Configuration

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
        
        transports:
            # Default transport
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
            
            # Tenant-aware transport
            tenant_async:
                dsn: 'tenant://default'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
                    max_delay: 0
        
        routing:
            'App\Message\*': tenant_async
            'App\Query\*': query.bus
```

### Environment Variables

```bash
# .env
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
```

## Basic Usage

### Creating Tenant-Aware Messages

```php
<?php

namespace App\Message;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class ProcessOrderMessage
{
    public function __construct(
        private int $orderId,
        private ?TenantInterface $tenant = null,
    ) {}

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(?TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

### Message Handler with Tenant Context

```php
<?php

namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Message\ProcessOrderMessage;
use App\Repository\OrderRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class ProcessOrderMessageHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private TenantContextInterface $tenantContext,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessOrderMessage $message): void
    {
        // Set tenant context from message
        $tenant = $message->getTenant();
        
        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
            
            $this->logger->info('Processing order for tenant', [
                'tenant_slug' => $tenant->getSlug(),
                'order_id' => $message->getOrderId(),
            ]);
        }

        try {
            // Process the order - queries are automatically filtered by tenant
            $order = $this->orderRepository->find($message->getOrderId());
            
            if (!$order) {
                throw new \RuntimeException('Order not found: ' . $message->getOrderId());
            }

            // Your order processing logic here
            $this->processOrder($order);
            
            $this->logger->info('Order processed successfully', [
                'tenant_slug' => $tenant?->getSlug(),
                'order_id' => $order->getId(),
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process order', [
                'tenant_slug' => $tenant?->getSlug(),
                'order_id' => $message->getOrderId(),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        } finally {
            // Clear tenant context
            $this->tenantContext->clear();
        }
    }

    private function processOrder(Order $order): void
    {
        // Update order status
        $order->setStatus('processing');
        
        // Send confirmation email
        // Calculate shipping
        // Update inventory
        // etc.
    }
}
```

### Dispatching Messages

```php
<?php

namespace App\Service;

use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\ProcessOrderMessage;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class OrderService
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TenantContextInterface $tenantContext,
    ) {}

    public function submitOrderForProcessing(Order $order): void
    {
        $tenant = $this->tenantContext->getTenant();
        
        // Create message with tenant context
        $message = new ProcessOrderMessage($order->getId(), $tenant);
        
        // Dispatch to tenant-specific queue
        $this->messageBus->dispatch($message);
    }
}
```

## Advanced Configuration

### Tenant-Specific Transport Configuration

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

class TenantMessengerConfigurationService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
        private TenantMessengerConfigurator $messengerConfigurator,
    ) {}

    public function configureMessengerSettings(array $settings): void
    {
        $this->validateMessengerSettings($settings);

        $this->settingsManager->setMultiple([
            'messenger_transport_dsn' => $settings['transport_dsn'],
            'messenger_bus' => $settings['bus_name'] ?? 'messenger.bus.default',
            'messenger_retry_max' => $settings['retry_max'] ?? 3,
            'messenger_retry_delay' => $settings['retry_delay'] ?? 1000,
            'messenger_retry_multiplier' => $settings['retry_multiplier'] ?? 2,
        ]);
    }

    public function getMessengerSettings(): array
    {
        return [
            'transport_dsn' => $this->messengerConfigurator->getTransportDsn(),
            'bus_name' => $this->messengerConfigurator->getBusName(),
            'retry_max' => $this->settingsManager->get('messenger_retry_max', 3),
            'retry_delay' => $this->settingsManager->get('messenger_retry_delay', 1000),
            'retry_multiplier' => $this->settingsManager->get('messenger_retry_multiplier', 2),
        ];
    }

    private function validateMessengerSettings(array $settings): void
    {
        if (empty($settings['transport_dsn'])) {
            throw new \InvalidArgumentException('Transport DSN is required');
        }

        // Validate DSN format
        if (!$this->isValidDsn($settings['transport_dsn'])) {
            throw new \InvalidArgumentException('Invalid transport DSN format');
        }
    }

    private function isValidDsn(string $dsn): bool
    {
        $validSchemes = ['amqp', 'redis', 'doctrine', 'sync', 'in-memory'];
        $parsed = parse_url($dsn);
        
        return $parsed !== false && in_array($parsed['scheme'] ?? '', $validSchemes);
    }
}
```

### Custom Message Middleware

```php
<?php

namespace App\Messenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\Stamp\TenantStamp;

class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Add tenant stamp if not already present
        if (!$envelope->last(TenantStamp::class)) {
            $tenant = $this->tenantContext->getTenant();
            
            if ($tenant) {
                $envelope = $envelope->with(new TenantStamp($tenant->getSlug()));
            }
        }

        // Set tenant context from stamp for handlers
        $tenantStamp = $envelope->last(TenantStamp::class);
        
        if ($tenantStamp) {
            // Resolve tenant from stamp and set context
            $tenant = $this->resolveTenantFromStamp($tenantStamp);
            $this->tenantContext->setTenant($tenant);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            // Clear tenant context after handling
            if ($tenantStamp) {
                $this->tenantContext->clear();
            }
        }
    }

    private function resolveTenantFromStamp(TenantStamp $stamp): ?TenantInterface
    {
        // Implementation to resolve tenant from stamp
        // This would typically use the tenant registry
        return null;
    }
}
```

### Tenant Stamp

```php
<?php

namespace Zhortein\MultiTenantBundle\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

class TenantStamp implements StampInterface
{
    public function __construct(
        private string $tenantSlug,
    ) {}

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}
```

## Message Types and Patterns

### Command Messages

```php
<?php

namespace App\Message\Command;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class CreateUserCommand
{
    public function __construct(
        private string $email,
        private string $name,
        private array $roles = [],
        private ?TenantInterface $tenant = null,
    ) {}

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(?TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

### Event Messages

```php
<?php

namespace App\Message\Event;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class UserCreatedEvent
{
    public function __construct(
        private int $userId,
        private string $email,
        private \DateTimeImmutable $createdAt,
        private ?TenantInterface $tenant = null,
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(?TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

### Query Messages

```php
<?php

namespace App\Message\Query;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class GetUserStatisticsQuery
{
    public function __construct(
        private \DateTimeInterface $startDate,
        private \DateTimeInterface $endDate,
        private ?TenantInterface $tenant = null,
    ) {}

    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeInterface
    {
        return $this->endDate;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(?TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

## Message Handlers

### Command Handler

```php
<?php

namespace App\MessageHandler\Command;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Message\Command\CreateUserCommand;
use App\Message\Event\UserCreatedEvent;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsMessageHandler]
class CreateUserCommandHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MessageBusInterface $eventBus,
        private TenantContextInterface $tenantContext,
    ) {}

    public function __invoke(CreateUserCommand $command): void
    {
        // Set tenant context
        $tenant = $command->getTenant();
        
        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        }

        try {
            // Create user
            $user = new User();
            $user->setEmail($command->getEmail());
            $user->setName($command->getName());
            $user->setRoles($command->getRoles());
            
            if ($tenant) {
                $user->setTenant($tenant);
            }

            $this->userRepository->save($user);

            // Dispatch event
            $event = new UserCreatedEvent(
                $user->getId(),
                $user->getEmail(),
                $user->getCreatedAt(),
                $tenant
            );

            $this->eventBus->dispatch($event);

        } finally {
            $this->tenantContext->clear();
        }
    }
}
```

### Event Handler

```php
<?php

namespace App\MessageHandler\Event;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Message\Event\UserCreatedEvent;
use App\Service\EmailService;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsMessageHandler]
class UserCreatedEventHandler
{
    public function __construct(
        private EmailService $emailService,
        private TenantContextInterface $tenantContext,
    ) {}

    public function __invoke(UserCreatedEvent $event): void
    {
        // Set tenant context
        $tenant = $event->getTenant();
        
        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        }

        try {
            // Send welcome email
            $this->emailService->sendWelcomeEmail(
                $event->getEmail(),
                'Welcome to our platform!'
            );

            // Log user creation
            $this->logUserCreation($event);

            // Update statistics
            $this->updateUserStatistics($event);

        } finally {
            $this->tenantContext->clear();
        }
    }

    private function logUserCreation(UserCreatedEvent $event): void
    {
        // Log user creation for analytics
    }

    private function updateUserStatistics(UserCreatedEvent $event): void
    {
        // Update tenant-specific user statistics
    }
}
```

### Query Handler

```php
<?php

namespace App\MessageHandler\Query;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Message\Query\GetUserStatisticsQuery;
use App\Repository\UserRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsMessageHandler]
class GetUserStatisticsQueryHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private TenantContextInterface $tenantContext,
    ) {}

    public function __invoke(GetUserStatisticsQuery $query): array
    {
        // Set tenant context
        $tenant = $query->getTenant();
        
        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        }

        try {
            return [
                'total_users' => $this->userRepository->countUsers(),
                'new_users' => $this->userRepository->countUsersBetween(
                    $query->getStartDate(),
                    $query->getEndDate()
                ),
                'active_users' => $this->userRepository->countActiveUsers(),
                'tenant_slug' => $tenant?->getSlug(),
            ];

        } finally {
            $this->tenantContext->clear();
        }
    }
}
```

## Scheduled Messages

### Tenant-Specific Cron Jobs

```php
<?php

namespace App\Service;

use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\Command\GenerateReportsCommand;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

class ScheduledTaskService
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function scheduleMonthlyReports(): void
    {
        // Schedule reports for all active tenants
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            if (!$tenant->isActive()) {
                continue;
            }

            $command = new GenerateReportsCommand(
                'monthly',
                new \DateTimeImmutable('first day of last month'),
                new \DateTimeImmutable('last day of last month'),
                $tenant
            );

            $this->messageBus->dispatch($command);
        }
    }

    public function scheduleDataCleanup(): void
    {
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            $command = new CleanupDataCommand(
                new \DateTimeImmutable('-90 days'),
                $tenant
            );

            $this->messageBus->dispatch($command);
        }
    }
}
```

### Cron Configuration

```yaml
# config/packages/cron.yaml
cron:
    jobs:
        monthly_reports:
            command: 'app:schedule-monthly-reports'
            schedule: '0 2 1 * *' # First day of month at 2 AM
            
        daily_cleanup:
            command: 'app:schedule-data-cleanup'
            schedule: '0 3 * * *' # Daily at 3 AM
```

## Testing

### Unit Testing Message Handlers

```php
<?php

namespace App\Tests\MessageHandler;

use PHPUnit\Framework\TestCase;
use App\MessageHandler\Command\CreateUserCommandHandler;
use App\Message\Command\CreateUserCommand;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class CreateUserCommandHandlerTest extends TestCase
{
    public function testHandleCreateUserCommand(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $eventBus = $this->createMock(MessageBusInterface::class);
        $tenantContext = $this->createMock(TenantContextInterface::class);

        $tenant = $this->createMockTenant('acme');
        $command = new CreateUserCommand('test@example.com', 'John Doe', ['ROLE_USER'], $tenant);

        $tenantContext
            ->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $userRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($user) {
                return $user->getEmail() === 'test@example.com'
                    && $user->getName() === 'John Doe';
            }));

        $eventBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(UserCreatedEvent::class));

        $handler = new CreateUserCommandHandler($userRepository, $eventBus, $tenantContext);
        $handler($command);
    }

    private function createMockTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        return $tenant;
    }
}
```

### Integration Testing

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\Command\CreateUserCommand;
use App\Repository\UserRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class MessengerIntegrationTest extends KernelTestCase
{
    public function testMessageHandlingWithTenantContext(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $messageBus = $container->get(MessageBusInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $tenantContext = $container->get(TenantContextInterface::class);

        // Create test tenant
        $tenant = $this->createTestTenant();
        $tenantContext->setTenant($tenant);

        // Dispatch command
        $command = new CreateUserCommand('test@example.com', 'John Doe', ['ROLE_USER'], $tenant);
        $messageBus->dispatch($command);

        // Process messages synchronously in test
        $this->processMessages();

        // Verify user was created
        $user = $userRepository->findOneBy(['email' => 'test@example.com']);
        $this->assertNotNull($user);
        $this->assertEquals('John Doe', $user->getName());
        $this->assertEquals($tenant->getId(), $user->getTenant()->getId());
    }

    private function processMessages(): void
    {
        // Process messages synchronously for testing
        $container = static::getContainer();
        $receiver = $container->get('messenger.receiver.tenant_async');
        
        while ($envelopes = $receiver->get()) {
            foreach ($envelopes as $envelope) {
                $receiver->ack($envelope);
            }
        }
    }
}
```

## Console Commands

### Process Messages

```bash
# Process messages for all tenants
php bin/console messenger:consume tenant_async

# Process messages with specific options
php bin/console messenger:consume tenant_async --limit=10 --time-limit=3600

# Process messages for specific tenant (if using tenant-specific queues)
php bin/console messenger:consume tenant_async_acme
```

### Monitor Message Queues

```bash
# Check message queue status
php bin/console messenger:stats

# Failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

### Tenant-Specific Commands

```bash
# Send test message for tenant
php bin/console tenant:message:send-test --tenant=acme --message="Test message"

# Process messages for specific tenant
php bin/console tenant:message:process --tenant=acme

# Clear messages for tenant
php bin/console tenant:message:clear --tenant=acme
```

## Monitoring and Debugging

### Message Logging

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        messenger:
            type: stream
            path: '%kernel.logs_dir%/messenger.log'
            level: debug
            channels: ['messenger']
```

### Performance Monitoring

```php
<?php

namespace App\Messenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Psr\Log\LoggerInterface;

class PerformanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Stopwatch $stopwatch,
        private LoggerInterface $logger,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = get_class($envelope->getMessage());
        $this->stopwatch->start($messageClass);

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            
            $event = $this->stopwatch->stop($messageClass);
            
            $this->logger->info('Message processed', [
                'message_class' => $messageClass,
                'duration_ms' => $event->getDuration(),
                'memory_mb' => $event->getMemory() / 1024 / 1024,
            ]);
            
            return $envelope;
            
        } catch (\Exception $e) {
            $this->stopwatch->stop($messageClass);
            
            $this->logger->error('Message processing failed', [
                'message_class' => $messageClass,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
```

## Best Practices

1. **Always Include Tenant Context**: Ensure messages carry tenant information
2. **Use Proper Message Types**: Distinguish between commands, events, and queries
3. **Handle Failures Gracefully**: Implement proper error handling and retry logic
4. **Monitor Performance**: Track message processing times and queue sizes
5. **Test Message Handlers**: Write comprehensive tests for message handlers
6. **Use Middleware**: Leverage middleware for cross-cutting concerns
7. **Isolate Tenant Data**: Ensure complete tenant isolation in message processing
8. **Configure Retries**: Set appropriate retry strategies for different message types
9. **Log Important Events**: Log message processing for debugging and monitoring
10. **Scale Appropriately**: Configure transport and worker settings for your load