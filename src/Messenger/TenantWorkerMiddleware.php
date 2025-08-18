<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Middleware that restores tenant context in worker processes.
 *
 * This middleware extracts tenant information from TenantStamp
 * and restores the tenant context for message handlers, including
 * configuring database session variables for Row-Level Security.
 */
final readonly class TenantWorkerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantRegistryInterface $tenantRegistry,
        private TenantSessionConfigurator $sessionConfigurator,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenantStamp = $envelope->last(TenantStamp::class);

        if (!$tenantStamp instanceof TenantStamp) {
            // No tenant stamp found, proceed without tenant context
            return $stack->next()->handle($envelope, $stack);
        }

        // Find tenant by ID
        $tenant = $this->tenantRegistry->findById($tenantStamp->getTenantId());

        if (null === $tenant) {
            // Tenant not found, proceed without tenant context
            return $stack->next()->handle($envelope, $stack);
        }

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        try {
            // Configure database session using TenantSessionConfigurator
            $this->sessionConfigurator->setConfig();

            // Process the message with tenant context
            return $stack->next()->handle($envelope, $stack);
        } finally {
            // Always clear tenant context after processing
            $this->tenantContext->clear();
        }
    }
}
