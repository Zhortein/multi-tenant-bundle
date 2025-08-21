<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Metrics;

/**
 * Null implementation of the metrics adapter.
 *
 * This adapter discards all metrics and is used as the default
 * implementation when no specific metrics backend is configured.
 */
final class NullMetricsAdapter implements MetricsAdapterInterface
{
    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        // No-op: discard the metric
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        // No-op: discard the metric
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        // No-op: discard the metric
    }

    public function timing(string $name, float $duration, array $labels = []): void
    {
        // No-op: discard the metric
    }
}
