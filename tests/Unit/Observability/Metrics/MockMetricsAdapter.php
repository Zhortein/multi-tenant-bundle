<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\Metrics;

use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

/**
 * Mock metrics adapter for testing purposes.
 */
final class MockMetricsAdapter implements MetricsAdapterInterface
{
    /** @var array<array{name: string, labels: array<string, mixed>, value: int}> */
    private array $counters = [];

    /** @var array<array{name: string, value: float, labels: array<string, mixed>}> */
    private array $gauges = [];

    /** @var array<array{name: string, value: float, labels: array<string, mixed>}> */
    private array $histograms = [];

    /** @var array<array{name: string, duration: float, labels: array<string, mixed>}> */
    private array $timings = [];

    public function counter(string $name, array $labels = [], int $value = 1): void
    {
        $this->counters[] = [
            'name' => $name,
            'labels' => $labels,
            'value' => $value,
        ];
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->gauges[] = [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
        ];
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $this->histograms[] = [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
        ];
    }

    public function timing(string $name, float $duration, array $labels = []): void
    {
        $this->timings[] = [
            'name' => $name,
            'duration' => $duration,
            'labels' => $labels,
        ];
    }

    /**
     * @return array<array{name: string, labels: array<string, mixed>, value: int}>
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /**
     * @return array<array{name: string, value: float, labels: array<string, mixed>}>
     */
    public function getGauges(): array
    {
        return $this->gauges;
    }

    /**
     * @return array<array{name: string, value: float, labels: array<string, mixed>}>
     */
    public function getHistograms(): array
    {
        return $this->histograms;
    }

    /**
     * @return array<array{name: string, duration: float, labels: array<string, mixed>}>
     */
    public function getTimings(): array
    {
        return $this->timings;
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->timings = [];
    }
}
