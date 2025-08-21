<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Middleware that attaches tenant information to outgoing messages.
 *
 * This middleware automatically adds a TenantStamp to messages when
 * a tenant context is available, ensuring tenant information is
 * propagated to async message handlers.
 */
final readonly class TenantSendingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Check if tenant context is available and no TenantStamp is already present
        if ($this->tenantContext->hasTenant() && null === $envelope->last(TenantStamp::class)) {
            $tenant = $this->tenantContext->getTenant();

            if (null !== $tenant) {
                $tenantId = (string) $tenant->getId();
                $envelope = $envelope->with(new TenantStamp($tenantId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
