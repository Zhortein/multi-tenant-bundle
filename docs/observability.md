# Observability

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Examples →](examples/observability-usage.md)

The multi-tenant bundle provides comprehensive observability features to help diagnose tenant issues in production environments. This includes events, metrics, and logging capabilities.

## Table of Contents

- [Overview](#overview)
- [Events](#events)
- [Metrics](#metrics)
- [Logging](#logging)
- [Configuration](#configuration)
- [Integration Examples](#integration-examples)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

## Overview

The observability system is designed with zero dependencies on specific APM vendors, providing a flexible interface that can be adapted to various monitoring solutions like Prometheus, StatsD, DataDog, or New Relic.

## Events

The bundle dispatches several PSR-14 compatible events that provide insight into tenant operations:

### TenantResolvedEvent

Dispatched when a tenant is successfully resolved by any resolver.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;

// Event properties
$event->getResolver();  // string - Name of the resolver that resolved the tenant
$event->getTenantId();  // string - ID of the resolved tenant
```

### TenantResolutionFailedEvent

Dispatched when tenant resolution fails.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;

// Event properties
$event->getResolver();  // string - Name of the resolver that failed
$event->getReason();    // string - Reason for the failure
$event->getContext();   // array - Additional context information
```

### TenantContextStartedEvent

Dispatched when a tenant context is initialized.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;

// Event properties
$event->getTenantId();  // string - ID of the tenant for which context was started
```

### TenantContextEndedEvent

Dispatched when a tenant context is cleared or switched.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;

// Event properties
$event->getTenantId();  // string|null - ID of the tenant for which context was ended
```

### TenantRlsAppliedEvent

Dispatched when PostgreSQL Row-Level Security (RLS) is applied for a tenant.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;

// Event properties
$event->getTenantId();      // string - ID of the tenant for which RLS was applied
$event->isSuccess();        // bool - Whether the RLS application was successful
$event->getErrorMessage();  // string|null - Error message if RLS application failed
```

### TenantHeaderRejectedEvent

Dispatched when a tenant header is rejected by the allow-list.

```php
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;

// Event properties
$event->getHeaderName();  // string - Name of the rejected header
```

## Metrics

The bundle provides a vendor-neutral metrics interface that can be implemented for various APM solutions.

### Default Metrics

The following metrics are automatically collected:

#### tenant_resolution_total
Counter tracking tenant resolution attempts.

**Labels:**
- `resolver`: Name of the resolver (e.g., "subdomain", "header", "path")
- `status`: Resolution status ("ok" or "error")
- `reason`: Failure reason (only for error status)

#### tenant_rls_apply_total
Counter tracking RLS application attempts.

**Labels:**
- `status`: Application status ("ok" or "error")

#### tenant_header_rejected_total
Counter tracking rejected headers.

**Labels:**
- `header`: Name of the rejected header

### Metrics Adapter Interface

```php
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

interface MetricsAdapterInterface
{
    public function counter(string $name, array $labels = [], int $value = 1): void;
    public function gauge(string $name, float $value, array $labels = []): void;
    public function histogram(string $name, float $value, array $labels = []): void;
    public function timing(string $name, float $duration, array $labels = []): void;
}
```

### Custom Metrics Adapter

To integrate with your preferred APM solution, create a custom adapter:

#### Prometheus Example

```php
use Prometheus\CollectorRegistry;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class PrometheusMetricsAdapter implements MetricsAdapterInterface
{
    public function __construct(
        private CollectorRegistry $registry,
        private string $namespace = 'multitenant'
    ) {
    }

    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        $counter = $this->registry->getOrRegisterCounter(
            $this->namespace,
            $name,
            'Multi-tenant counter metric',
            array_keys($labels)
        );
        
        $counter->incBy($value, array_values($labels));
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            $this->namespace,
            $name,
            'Multi-tenant gauge metric',
            array_keys($labels)
        );
        
        $gauge->set($value, array_values($labels));
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $histogram = $this->registry->getOrRegisterHistogram(
            $this->namespace,
            $name,
            'Multi-tenant histogram metric',
            array_keys($labels)
        );
        
        $histogram->observe($value, array_values($labels));
    }

    public function timing(string $name, float $duration, array $labels = []): void
    {
        $this->histogram($name . '_duration_seconds', $duration, $labels);
    }
}
```

#### Service Configuration

```yaml
# config/services.yaml
services:
    App\Metrics\PrometheusMetricsAdapter:
        arguments:
            $registry: '@prometheus.collector_registry'
    
    # Override the default null adapter
    Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface:
        alias: App\Metrics\PrometheusMetricsAdapter
```

#### StatsD Example

```php
use League\StatsD\Client;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class StatsDMetricsAdapter implements MetricsAdapterInterface
{
    public function __construct(
        private Client $client,
        private string $prefix = 'multitenant'
    ) {
    }

    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        $metricName = $this->buildMetricName($name, $labels);
        $this->client->increment($metricName, $value);
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $metricName = $this->buildMetricName($name, $labels);
        $this->client->gauge($metricName, $value);
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $metricName = $this->buildMetricName($name, $labels);
        $this->client->histogram($metricName, $value);
    }

    public function timing(string $name, float $duration, array $labels = []): void
    {
        $metricName = $this->buildMetricName($name, $labels);
        $this->client->timing($metricName, $duration * 1000); // Convert to milliseconds
    }

    private function buildMetricName(string $name, array $labels): string
    {
        $metricName = $this->prefix . '.' . $name;
        
        foreach ($labels as $key => $value) {
            $metricName .= '.' . $key . '_' . $value;
        }
        
        return $metricName;
    }
}
```

## Logging

The bundle automatically logs tenant-related events with appropriate context information.

### Log Levels

- **INFO**: Successful operations (tenant resolved, context started/ended, RLS applied)
- **WARNING**: Non-critical issues (resolution failed, header rejected)
- **ERROR**: Critical failures (RLS application failed)

### Log Context

All log entries include relevant context:

```php
// Example log entries
[INFO] Tenant successfully resolved {"tenant_id": "123", "resolver": "subdomain"}
[WARNING] Tenant resolution failed {"resolver": "header", "reason": "no_tenant_found", "context": {...}}
[INFO] Tenant context started {"tenant_id": "123"}
[ERROR] Tenant RLS application failed {"tenant_id": "123", "error_message": "Connection failed"}
```

### Custom Event Listeners

You can create custom event listeners to handle observability events:

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
        // Custom logic for tenant resolution
        $this->notifyExternalSystem($event->getTenantId(), $event->getResolver());
    }
}
```

## Monitoring Best Practices

### Alerting

Set up alerts for critical metrics:

- High error rate in `tenant_resolution_total{status="error"}`
- Frequent `tenant_rls_apply_total{status="error"}` events
- Unusual patterns in `tenant_header_rejected_total`

### Dashboards

Create dashboards to visualize:

- Tenant resolution success/failure rates by resolver
- RLS application success rates
- Tenant context lifecycle patterns
- Header rejection patterns

### Performance Monitoring

Monitor the performance impact of tenant resolution:

- Track resolution time by resolver type
- Monitor database connection switching overhead
- Observe RLS policy application latency

## Troubleshooting

### Common Issues

1. **High Resolution Failures**: Check resolver configuration and tenant data
2. **RLS Application Failures**: Verify PostgreSQL connection and permissions
3. **Header Rejections**: Review header allow-list configuration
4. **Missing Events**: Ensure event dispatcher is properly configured

### Debug Mode

Enable debug logging to get detailed information about tenant operations:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        main:
            level: debug
            channels: ["!event"]
```

This comprehensive observability system provides the foundation for monitoring and troubleshooting multi-tenant applications in production environments.

## See Also

- **[Observability Usage Examples](examples/observability-usage.md)** - Practical implementation examples for Prometheus, StatsD, and custom metrics
- **[Testing Documentation](testing.md)** - Testing observability features with the Test Kit
- **[Configuration Reference](configuration.md)** - Observability configuration options

---

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Examples →](examples/observability-usage.md)