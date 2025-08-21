<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Observability\Metrics\NullMetricsAdapter;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\Metrics\NullMetricsAdapter
 */
final class NullMetricsAdapterTest extends TestCase
{
    private NullMetricsAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new NullMetricsAdapter();
    }

    public function testCounterDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        
        $this->adapter->counter('test_counter');
        $this->adapter->counter('test_counter', ['label' => 'value'], 5);
    }

    public function testGaugeDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        
        $this->adapter->gauge('test_gauge', 42.5);
        $this->adapter->gauge('test_gauge', 100.0, ['label' => 'value']);
    }

    public function testHistogramDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        
        $this->adapter->histogram('test_histogram', 0.5);
        $this->adapter->histogram('test_histogram', 1.2, ['label' => 'value']);
    }

    public function testTimingDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        
        $this->adapter->timing('test_timing', 0.123);
        $this->adapter->timing('test_timing', 0.456, ['label' => 'value']);
    }
}