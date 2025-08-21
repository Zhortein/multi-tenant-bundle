<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Metrics;

/**
 * Interface for metrics collection adapters.
 *
 * This interface provides a vendor-neutral way to collect metrics
 * from the multi-tenant bundle. Implementations can integrate with
 * various APM solutions like Prometheus, StatsD, DataDog, etc.
 */
interface MetricsAdapterInterface
{
    /**
     * Increments a counter metric.
     *
     * @param string               $name   The metric name
     * @param array<string, mixed> $labels The metric labels
     * @param int                  $value  The value to increment by (default: 1)
     */
    public function counter(string $name, array $labels = [], int $value = 1): void;

    /**
     * Records a gauge metric.
     *
     * @param string               $name   The metric name
     * @param float                $value  The gauge value
     * @param array<string, mixed> $labels The metric labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;

    /**
     * Records a histogram metric.
     *
     * @param string               $name   The metric name
     * @param float                $value  The observed value
     * @param array<string, mixed> $labels The metric labels
     */
    public function histogram(string $name, float $value, array $labels = []): void;

    /**
     * Records a timing metric.
     *
     * @param string               $name     The metric name
     * @param float                $duration The duration in seconds
     * @param array<string, mixed> $labels   The metric labels
     */
    public function timing(string $name, float $duration, array $labels = []): void;
}