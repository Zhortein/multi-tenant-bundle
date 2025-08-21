# Observability

This directory contains the observability features for the multi-tenant bundle, providing comprehensive monitoring, metrics collection, and logging capabilities.

## Components

### Events (`Event/`)
PSR-14 compatible events that are dispatched during tenant operations:

- **TenantResolvedEvent** - When a tenant is successfully resolved
- **TenantResolutionFailedEvent** - When tenant resolution fails
- **TenantContextStartedEvent** - When tenant context is initialized
- **TenantContextEndedEvent** - When tenant context is cleared
- **TenantRlsAppliedEvent** - When PostgreSQL RLS is applied
- **TenantHeaderRejectedEvent** - When a header is rejected by allow-list

### Event Subscribers (`EventSubscriber/`)
Automatic event handlers that provide observability features:

- **TenantLoggingSubscriber** - Logs tenant events with structured context
- **TenantMetricsSubscriber** - Collects metrics for tenant operations

### Metrics (`Metrics/`)
Vendor-neutral metrics collection system:

- **MetricsAdapterInterface** - Interface for APM integration
- **NullMetricsAdapter** - Default no-op implementation

## Usage

The observability system is automatically enabled when the bundle is installed. Events are dispatched by core components and handled by the registered subscribers.

### Custom Metrics Adapter

To integrate with your APM solution, implement the `MetricsAdapterInterface`:

```php
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class MyMetricsAdapter implements MetricsAdapterInterface
{
    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        // Your implementation
    }
    
    // ... other methods
}
```

Then register it in your services:

```yaml
services:
    Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface:
        alias: App\Metrics\MyMetricsAdapter
```

### Custom Event Listeners

You can create custom event listeners to handle tenant events:

```php
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;

class CustomTenantObserver implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
        ];
    }

    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        // Custom logic
    }
}
```

## Metrics Collected

- `tenant_resolution_total` - Counter for tenant resolution attempts
- `tenant_rls_apply_total` - Counter for RLS application attempts  
- `tenant_header_rejected_total` - Counter for rejected headers

## Documentation

See `docs/observability.md` for complete documentation with examples for Prometheus, StatsD, and other APM solutions.