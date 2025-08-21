<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantMetricsSubscriber;
use Zhortein\MultiTenantBundle\Tests\Unit\Observability\Metrics\MockMetricsAdapter;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantMetricsSubscriber
 */
final class TenantMetricsSubscriberTest extends TestCase
{
    private MockMetricsAdapter $metricsAdapter;
    private TenantMetricsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->metricsAdapter = new MockMetricsAdapter();
        $this->subscriber = new TenantMetricsSubscriber($this->metricsAdapter);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = TenantMetricsSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TenantResolvedEvent::class, $events);
        $this->assertArrayHasKey(TenantResolutionFailedEvent::class, $events);
        $this->assertArrayHasKey(TenantRlsAppliedEvent::class, $events);
        $this->assertArrayHasKey(TenantHeaderRejectedEvent::class, $events);

        $this->assertSame('onTenantResolved', $events[TenantResolvedEvent::class]);
        $this->assertSame('onTenantResolutionFailed', $events[TenantResolutionFailedEvent::class]);
        $this->assertSame('onTenantRlsApplied', $events[TenantRlsAppliedEvent::class]);
        $this->assertSame('onTenantHeaderRejected', $events[TenantHeaderRejectedEvent::class]);
    }

    public function testOnTenantResolved(): void
    {
        $event = new TenantResolvedEvent('subdomain', '123');

        $this->subscriber->onTenantResolved($event);

        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_resolution_total', $counter['name']);
        $this->assertSame(['resolver' => 'subdomain', 'status' => 'ok'], $counter['labels']);
        $this->assertSame(1, $counter['value']);
    }

    public function testOnTenantResolutionFailed(): void
    {
        $event = new TenantResolutionFailedEvent('header', 'no_tenant_found', ['uri' => '/test']);

        $this->subscriber->onTenantResolutionFailed($event);

        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_resolution_total', $counter['name']);
        $this->assertSame([
            'resolver' => 'header',
            'status' => 'error',
            'reason' => 'no_tenant_found',
        ], $counter['labels']);
        $this->assertSame(1, $counter['value']);
    }

    public function testOnTenantRlsAppliedSuccess(): void
    {
        $event = new TenantRlsAppliedEvent('123', true);

        $this->subscriber->onTenantRlsApplied($event);

        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_rls_apply_total', $counter['name']);
        $this->assertSame(['status' => 'ok'], $counter['labels']);
        $this->assertSame(1, $counter['value']);
    }

    public function testOnTenantRlsAppliedFailure(): void
    {
        $event = new TenantRlsAppliedEvent('123', false, 'Connection failed');

        $this->subscriber->onTenantRlsApplied($event);

        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_rls_apply_total', $counter['name']);
        $this->assertSame(['status' => 'error'], $counter['labels']);
        $this->assertSame(1, $counter['value']);
    }

    public function testOnTenantHeaderRejected(): void
    {
        $event = new TenantHeaderRejectedEvent('X-Custom-Tenant');

        $this->subscriber->onTenantHeaderRejected($event);

        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_header_rejected_total', $counter['name']);
        $this->assertSame(['header' => 'X-Custom-Tenant'], $counter['labels']);
        $this->assertSame(1, $counter['value']);
    }
}