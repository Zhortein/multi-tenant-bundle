<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Messenger\Internal\MessageClassification;

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
        $classification = MessageClassification::fromEnvelope($envelope);
        if (!$classification->tenantAware) {
            return $stack->next()->handle($envelope, $stack);
        }

        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            throw new MissingTenantContextException('A tenant-aware message cannot be sent without a tenant context.');
        }

        $tenantId = (string) $tenant->getId();
        foreach ($classification->tenantStamps as $stamp) {
            if ($stamp->getTenantId() !== $tenantId) {
                throw new TenantMismatchException('TenantStamp conflicts with the current tenant context.');
            }
        }

        if ([] === $classification->tenantStamps) {
            $envelope = $envelope->with(new TenantStamp($tenantId));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
