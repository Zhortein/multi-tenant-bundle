<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;

/**
 * Event subscriber that collects metrics from tenant-related events.
 *
 * This subscriber listens to various tenant events and increments
 * appropriate counters to provide observability into tenant operations.
 */
final readonly class TenantMetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MetricsAdapterInterface $metricsAdapter,
    ) {
    }

    /**
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
            TenantResolutionFailedEvent::class => 'onTenantResolutionFailed',
            TenantRlsAppliedEvent::class => 'onTenantRlsApplied',
            TenantHeaderRejectedEvent::class => 'onTenantHeaderRejected',
        ];
    }

    /**
     * Handles successful tenant resolution events.
     */
    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $this->metricsAdapter->counter(
            'tenant_resolution_total',
            [
                'resolver' => $event->getResolver(),
                'status' => 'ok',
            ]
        );
    }

    /**
     * Handles failed tenant resolution events.
     */
    public function onTenantResolutionFailed(TenantResolutionFailedEvent $event): void
    {
        $this->metricsAdapter->counter(
            'tenant_resolution_total',
            [
                'resolver' => $event->getResolver(),
                'status' => 'error',
                'reason' => $event->getReason(),
            ]
        );
    }

    /**
     * Handles RLS application events.
     */
    public function onTenantRlsApplied(TenantRlsAppliedEvent $event): void
    {
        $this->metricsAdapter->counter(
            'tenant_rls_apply_total',
            [
                'status' => $event->isSuccess() ? 'ok' : 'error',
            ]
        );
    }

    /**
     * Handles header rejection events.
     */
    public function onTenantHeaderRejected(TenantHeaderRejectedEvent $event): void
    {
        $this->metricsAdapter->counter(
            'tenant_header_rejected_total',
            [
                'header' => $event->getHeaderName(),
            ]
        );
    }
}