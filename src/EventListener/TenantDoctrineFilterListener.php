<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Event listener that automatically enables and configures the Doctrine
 * tenant filter based on the current tenant context.
 *
 * This listener ensures that all database queries are automatically
 * filtered to only return data for the current tenant.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final readonly class TenantDoctrineFilterListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Handles the kernel request event to configure Doctrine filters.
     *
     * @param RequestEvent $event The request event
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests, not sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            $this->logger?->debug('No tenant in context, skipping Doctrine filter setup');

            return;
        }

        try {
            $filters = $this->entityManager->getFilters();

            // Enable the tenant filter if not already enabled
            if (!$filters->isEnabled('tenant')) {
                $filters->enable('tenant');
            }

            // Configure the filter with the current tenant ID
            $tenantFilter = $filters->getFilter('tenant');
            $tenantFilter->setParameter('tenant_id', $tenant->getId());

            $this->logger?->debug('Doctrine tenant filter configured', [
                'tenant_id' => $tenant->getId(),
                'tenant_slug' => $tenant->getSlug(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger?->error('Failed to configure Doctrine tenant filter', [
                'exception' => $exception->getMessage(),
                'tenant_id' => $tenant->getId(),
            ]);

            // Don't throw the exception to avoid breaking the request
            // The application should handle this gracefully
        }
    }
}
