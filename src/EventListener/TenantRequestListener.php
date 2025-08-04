<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * Event listener that automatically resolves and sets the tenant context
 * for incoming HTTP requests.
 *
 * This listener runs early in the request lifecycle to ensure the tenant
 * context is available for all subsequent processing.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class TenantRequestListener
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantResolverInterface $tenantResolver,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Handles the kernel request event to resolve and set tenant context.
     *
     * @param RequestEvent $event The request event
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests, not sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            $tenant = $this->tenantResolver->resolve($request);

            if (null !== $tenant) {
                $this->tenantContext->setTenant($tenant);

                $this->logger?->info('Tenant resolved and set in context', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_slug' => $tenant->getSlug(),
                    'request_uri' => $request->getRequestUri(),
                ]);
            } else {
                $this->logger?->debug('No tenant resolved for request', [
                    'request_uri' => $request->getRequestUri(),
                    'host' => $request->getHost(),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger?->error('Failed to resolve tenant from request', [
                'exception' => $exception->getMessage(),
                'request_uri' => $request->getRequestUri(),
                'host' => $request->getHost(),
            ]);

            // Don't throw the exception to avoid breaking the request
            // The application should handle missing tenant context gracefully
        }
    }
}
