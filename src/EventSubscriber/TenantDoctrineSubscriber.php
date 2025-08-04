<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;

/**
 * Event subscriber for tenant-aware database connection switching.
 *
 * This subscriber handles switching database connections based on the current tenant
 * when using separate databases per tenant strategy.
 */
final class TenantDoctrineSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantConnectionResolverInterface $connectionResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 35]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            return;
        }

        // Switch to tenant-specific database connection if using separate database strategy
        $this->connectionResolver->switchToTenantConnection($tenant);
    }
}
