# Observability Usage Examples

> 📖 **Navigation**: [← Back to Examples Index](../index.md#examples) | [← Observability Documentation](../observability.md) | [Documentation Index →](../index.md)

This document provides practical examples of using the observability features in the multi-tenant bundle.

## Table of Contents

- [Basic Event Listening](#basic-event-listening)
- [Prometheus Integration](#prometheus-integration)
- [StatsD Integration](#statsd-integration)
- [Custom Metrics Collection](#custom-metrics-collection)
- [Structured Logging](#structured-logging)
- [Monitoring Dashboards](#monitoring-dashboards)
- [Alerting Examples](#alerting-examples)

## Basic Event Listening

### Custom Event Subscriber

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;

class TenantAnalyticsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AnalyticsService $analytics
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
            TenantResolutionFailedEvent::class => 'onTenantResolutionFailed',
        ];
    }

    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $this->analytics->track('tenant_resolved', [
            'tenant_id' => $event->getTenantId(),
            'resolver' => $event->getResolver(),
            'timestamp' => time(),
        ]);
    }

    public function onTenantResolutionFailed(TenantResolutionFailedEvent $event): void
    {
        $this->analytics->track('tenant_resolution_failed', [
            'resolver' => $event->getResolver(),
            'reason' => $event->getReason(),
            'context' => $event->getContext(),
            'timestamp' => time(),
        ]);
    }
}
```

### Service Configuration

```yaml
# config/services.yaml
services:
    App\EventSubscriber\TenantAnalyticsSubscriber:
        arguments:
            $analytics: '@app.analytics_service'
        tags:
            - { name: kernel.event_subscriber }
```

## Prometheus Integration

### Prometheus Metrics Adapter

```php
<?php

namespace App\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class PrometheusMetricsAdapter implements MetricsAdapterInterface
{
    private array $counters = [];
    private array $gauges = [];
    private array $histograms = [];

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $namespace = 'multitenant',
    ) {
    }

    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        $counter = $this->getOrCreateCounter($name, array_keys($labels));
        $counter->incBy($value, array_values($labels));
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $gauge = $this->getOrCreateGauge($name, array_keys($labels));
        $gauge->set($value, array_values($labels));
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $histogram = $this->getOrCreateHistogram($name, array_keys($labels));
        $histogram->observe($value, array_values($labels));
    }

    public function timing(string $name, float $duration, array $labels = []): void
    {
        $this->histogram($name . '_duration_seconds', $duration, $labels);
    }

    private function getOrCreateCounter(string $name, array $labelNames): Counter
    {
        $key = $name . ':' . implode(',', $labelNames);
        
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $name,
                'Multi-tenant counter metric',
                $labelNames
            );
        }

        return $this->counters[$key];
    }

    private function getOrCreateGauge(string $name, array $labelNames): Gauge
    {
        $key = $name . ':' . implode(',', $labelNames);
        
        if (!isset($this->gauges[$key])) {
            $this->gauges[$key] = $this->registry->getOrRegisterGauge(
                $this->namespace,
                $name,
                'Multi-tenant gauge metric',
                $labelNames
            );
        }

        return $this->gauges[$key];
    }

    private function getOrCreateHistogram(string $name, array $labelNames): Histogram
    {
        $key = $name . ':' . implode(',', $labelNames);
        
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $name,
                'Multi-tenant histogram metric',
                $labelNames,
                [0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0] // Default buckets
            );
        }

        return $this->histograms[$key];
    }
}
```

### Prometheus Service Configuration

```yaml
# config/services.yaml
services:
    # Prometheus collector registry
    prometheus.collector_registry:
        class: Prometheus\CollectorRegistry
        factory: ['Prometheus\Storage\InMemory', 'fromDefaults']

    # Prometheus metrics adapter
    App\Metrics\PrometheusMetricsAdapter:
        arguments:
            $registry: '@prometheus.collector_registry'
            $namespace: 'multitenant'

    # Override the default metrics adapter
    Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface:
        alias: App\Metrics\PrometheusMetricsAdapter
```

### Prometheus Metrics Endpoint

```php
<?php

namespace App\Controller;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MetricsController extends AbstractController
{
    #[Route('/metrics', name: 'prometheus_metrics')]
    public function metrics(CollectorRegistry $registry): Response
    {
        $renderer = new RenderTextFormat();
        $result = $renderer->render($registry->getMetricFamilySamples());

        return new Response($result, 200, [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
        ]);
    }
}
```

## StatsD Integration

### StatsD Metrics Adapter

```php
<?php

namespace App\Metrics;

use League\StatsD\Client;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class StatsDMetricsAdapter implements MetricsAdapterInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $prefix = 'multitenant',
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
            $metricName .= '.' . $key . '_' . str_replace(['.', ' ', '-'], '_', (string) $value);
        }
        
        return $metricName;
    }
}
```

### StatsD Service Configuration

```yaml
# config/services.yaml
services:
    # StatsD client
    statsd.client:
        class: League\StatsD\Client
        arguments:
            - '@statsd.connection'

    statsd.connection:
        class: League\StatsD\Connection\UdpSocket
        arguments:
            - '%env(STATSD_HOST)%'
            - '%env(int:STATSD_PORT)%'

    # StatsD metrics adapter
    App\Metrics\StatsDMetricsAdapter:
        arguments:
            $client: '@statsd.client'
            $prefix: 'multitenant'

    # Override the default metrics adapter
    Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface:
        alias: App\Metrics\StatsDMetricsAdapter
```

### Environment Configuration

```bash
# .env
STATSD_HOST=localhost
STATSD_PORT=8125
```

## Custom Metrics Collection

### Business Metrics Subscriber

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class BusinessMetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MetricsAdapterInterface $metrics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
            TenantContextStartedEvent::class => 'onTenantContextStarted',
        ];
    }

    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        // Track tenant activity
        $this->metrics->counter('tenant_activity_total', [
            'tenant_id' => $event->getTenantId(),
            'resolver' => $event->getResolver(),
        ]);

        // Track resolver usage
        $this->metrics->counter('resolver_usage_total', [
            'resolver' => $event->getResolver(),
        ]);
    }

    public function onTenantContextStarted(TenantContextStartedEvent $event): void
    {
        // Track active tenant sessions
        $this->metrics->gauge('active_tenant_sessions', 1, [
            'tenant_id' => $event->getTenantId(),
        ]);
    }
}
```

### Performance Monitoring

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

class PerformanceMetricsSubscriber implements EventSubscriberInterface
{
    private float $requestStartTime;

    public function __construct(
        private readonly MetricsAdapterInterface $metrics,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1000],
            KernelEvents::RESPONSE => ['onKernelResponse', -1000],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestStartTime = microtime(true);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $duration = microtime(true) - $this->requestStartTime;
        $tenant = $this->tenantContext->getTenant();

        $labels = [
            'method' => $event->getRequest()->getMethod(),
            'status_code' => (string) $event->getResponse()->getStatusCode(),
        ];

        if ($tenant) {
            $labels['tenant_id'] = (string) $tenant->getId();
        }

        // Track request duration
        $this->metrics->timing('http_request_duration', $duration, $labels);

        // Track request count
        $this->metrics->counter('http_requests_total', $labels);
    }
}
```

## Structured Logging

### Custom Log Processor

```php
<?php

namespace App\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantLogProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $tenant = $this->tenantContext->getTenant();
        
        if ($tenant) {
            $record->extra['tenant_id'] = (string) $tenant->getId();
            $record->extra['tenant_slug'] = $tenant->getSlug();
        }

        return $record;
    }
}
```

### Monolog Configuration

```yaml
# config/packages/monolog.yaml
monolog:
    processors:
        tenant_processor:
            class: App\Monolog\TenantLogProcessor
            arguments:
                $tenantContext: '@Zhortein\MultiTenantBundle\Context\TenantContextInterface'
            tags:
                - { name: monolog.processor }

    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            channels: ["!event"]
            formatter: json
        
        tenant_specific:
            type: stream
            path: "%kernel.logs_dir%/tenant-%extra.tenant_id%.log"
            level: info
            formatter: json
```

## Monitoring Dashboards

### Grafana Dashboard Configuration

```json
{
  "dashboard": {
    "title": "Multi-Tenant Application Monitoring",
    "panels": [
      {
        "title": "Tenant Resolution Rate",
        "type": "stat",
        "targets": [
          {
            "expr": "rate(multitenant_tenant_resolution_total{status=\"ok\"}[5m])",
            "legendFormat": "Success Rate"
          },
          {
            "expr": "rate(multitenant_tenant_resolution_total{status=\"error\"}[5m])",
            "legendFormat": "Error Rate"
          }
        ]
      },
      {
        "title": "Tenant Resolution by Resolver",
        "type": "piechart",
        "targets": [
          {
            "expr": "sum by (resolver) (multitenant_tenant_resolution_total)",
            "legendFormat": "{{resolver}}"
          }
        ]
      },
      {
        "title": "RLS Application Success Rate",
        "type": "gauge",
        "targets": [
          {
            "expr": "rate(multitenant_tenant_rls_apply_total{status=\"ok\"}[5m]) / rate(multitenant_tenant_rls_apply_total[5m]) * 100",
            "legendFormat": "Success %"
          }
        ]
      },
      {
        "title": "Request Duration by Tenant",
        "type": "graph",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(multitenant_http_request_duration_seconds_bucket[5m])) by (tenant_id)",
            "legendFormat": "{{tenant_id}} - 95th percentile"
          }
        ]
      }
    ]
  }
}
```

## Alerting Examples

### Prometheus Alerting Rules

```yaml
# alerts/multitenant.yml
groups:
  - name: multitenant
    rules:
      - alert: HighTenantResolutionFailureRate
        expr: rate(multitenant_tenant_resolution_total{status="error"}[5m]) / rate(multitenant_tenant_resolution_total[5m]) > 0.1
        for: 2m
        labels:
          severity: warning
        annotations:
          summary: "High tenant resolution failure rate"
          description: "Tenant resolution failure rate is {{ $value | humanizePercentage }} for resolver {{ $labels.resolver }}"

      - alert: RLSApplicationFailure
        expr: rate(multitenant_tenant_rls_apply_total{status="error"}[5m]) > 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "RLS application failures detected"
          description: "PostgreSQL Row-Level Security application is failing"

      - alert: TenantHeaderRejectionSpike
        expr: rate(multitenant_tenant_header_rejected_total[5m]) > 10
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High rate of tenant header rejections"
          description: "Header {{ $labels.header }} is being rejected at {{ $value }} requests/second"

      - alert: SlowTenantRequests
        expr: histogram_quantile(0.95, rate(multitenant_http_request_duration_seconds_bucket[5m])) > 2
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Slow tenant requests detected"
          description: "95th percentile request duration is {{ $value }}s for tenant {{ $labels.tenant_id }}"
```

### Slack Notification Configuration

```yaml
# config/packages/notifier.yaml
framework:
    notifier:
        texter_transports:
            slack: '%env(SLACK_DSN)%'

        channel_policy:
            urgent: ['slack']
            high: ['slack']
            medium: ['slack']
            low: []
```

### Custom Alert Handler

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;

class AlertingSubscriber implements EventSubscriberInterface
{
    private int $failureCount = 0;
    private \DateTimeImmutable $lastAlert;

    public function __construct(
        private readonly NotifierInterface $notifier,
    ) {
        $this->lastAlert = new \DateTimeImmutable('-1 hour');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolutionFailedEvent::class => 'onTenantResolutionFailed',
            TenantRlsAppliedEvent::class => 'onTenantRlsApplied',
        ];
    }

    public function onTenantResolutionFailed(TenantResolutionFailedEvent $event): void
    {
        $this->failureCount++;

        // Alert if we have more than 10 failures in the last minute
        if ($this->failureCount > 10 && $this->lastAlert < new \DateTimeImmutable('-1 minute')) {
            $notification = new Notification(
                'High tenant resolution failure rate detected',
                ['slack']
            );
            
            $notification->content(sprintf(
                'Resolver: %s, Reason: %s, Failures: %d',
                $event->getResolver(),
                $event->getReason(),
                $this->failureCount
            ));

            $this->notifier->send($notification);
            $this->lastAlert = new \DateTimeImmutable();
            $this->failureCount = 0;
        }
    }

    public function onTenantRlsApplied(TenantRlsAppliedEvent $event): void
    {
        if (!$event->isSuccess()) {
            $notification = new Notification(
                'CRITICAL: RLS Application Failed',
                ['slack']
            );
            
            $notification->content(sprintf(
                'Tenant: %s, Error: %s',
                $event->getTenantId(),
                $event->getErrorMessage() ?? 'Unknown error'
            ));

            $this->notifier->send($notification);
        }
    }
}
```

These examples demonstrate how to leverage the observability features for comprehensive monitoring, alerting, and performance analysis of your multi-tenant application.

## See Also

- **[Observability Documentation](../observability.md)** - Complete observability system reference
- **[Configuration Reference](../configuration.md)** - Observability configuration options
- **[Testing Documentation](../testing.md)** - Testing observability features
- **[Basic Usage Examples](basic-usage.md)** - General bundle usage examples

---

> 📖 **Navigation**: [← Back to Examples Index](../index.md#examples) | [← Observability Documentation](../observability.md) | [Documentation Index →](../index.md)