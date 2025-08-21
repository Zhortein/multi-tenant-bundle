<?php

declare(strict_types=1);

namespace App\Metrics;

use Prometheus\CollectorRegistry;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

/**
 * Example Prometheus metrics adapter for the multi-tenant bundle.
 *
 * This adapter integrates with the Prometheus PHP client to collect
 * tenant-related metrics for monitoring and alerting.
 *
 * Installation:
 * composer require promphp/prometheus_client_php
 *
 * Usage:
 * 1. Register this service in your container
 * 2. Override the default MetricsAdapterInterface alias
 * 3. Configure Prometheus scraping endpoint
 */
final class PrometheusMetricsAdapter implements MetricsAdapterInterface
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $namespace = 'multitenant',
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