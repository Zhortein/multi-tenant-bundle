<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;

/**
 * Event subscriber that logs tenant-related events.
 *
 * This subscriber provides comprehensive logging for tenant operations
 * to help with debugging and monitoring in production environments.
 */
final readonly class TenantLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
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
            TenantContextStartedEvent::class => 'onTenantContextStarted',
            TenantContextEndedEvent::class => 'onTenantContextEnded',
            TenantRlsAppliedEvent::class => 'onTenantRlsApplied',
            TenantHeaderRejectedEvent::class => 'onTenantHeaderRejected',
        ];
    }

    /**
     * Handles successful tenant resolution events.
     */
    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $this->logger?->info('Tenant successfully resolved', [
            'tenant_id' => $event->getTenantId(),
            'resolver' => $event->getResolver(),
        ]);
    }

    /**
     * Handles failed tenant resolution events.
     */
    public function onTenantResolutionFailed(TenantResolutionFailedEvent $event): void
    {
        $this->logger?->warning('Tenant resolution failed', [
            'resolver' => $event->getResolver(),
            'reason' => $event->getReason(),
            'context' => $event->getContext(),
        ]);
    }

    /**
     * Handles tenant context started events.
     */
    public function onTenantContextStarted(TenantContextStartedEvent $event): void
    {
        $this->logger?->info('Tenant context started', [
            'tenant_id' => $event->getTenantId(),
        ]);
    }

    /**
     * Handles tenant context ended events.
     */
    public function onTenantContextEnded(TenantContextEndedEvent $event): void
    {
        $this->logger?->info('Tenant context ended', [
            'tenant_id' => $event->getTenantId(),
        ]);
    }

    /**
     * Handles RLS application events.
     */
    public function onTenantRlsApplied(TenantRlsAppliedEvent $event): void
    {
        if ($event->isSuccess()) {
            $this->logger?->info('Tenant RLS successfully applied', [
                'tenant_id' => $event->getTenantId(),
            ]);
        } else {
            $this->logger?->error('Tenant RLS application failed', [
                'tenant_id' => $event->getTenantId(),
                'error_message' => $event->getErrorMessage(),
            ]);
        }
    }

    /**
     * Handles header rejection events.
     */
    public function onTenantHeaderRejected(TenantHeaderRejectedEvent $event): void
    {
        $this->logger?->warning('Tenant header rejected by allow-list', [
            'header_name' => $event->getHeaderName(),
        ]);
    }
}
