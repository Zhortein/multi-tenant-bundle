<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;

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
        $message = $envelope->getMessage();
        $tenantAware = $message instanceof TenantAwareMessageInterface;
        $global = $message instanceof GlobalMessageInterface;

        if ($tenantAware === $global) {
            throw new UnclassifiedMessageException($tenantAware ? 'A message cannot be both tenant-aware and global.' : 'A message must implement TenantAwareMessageInterface or GlobalMessageInterface.');
        }

        $stamps = $envelope->all(TenantStamp::class);
        if ($global) {
            if ([] !== $stamps) {
                throw new TenantMismatchException('A global message cannot carry a TenantStamp.');
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            throw new MissingTenantContextException('A tenant-aware message cannot be sent without a tenant context.');
        }

        $tenantId = (string) $tenant->getId();
        foreach ($stamps as $stamp) {
            if ($stamp->getTenantId() !== $tenantId) {
                throw new TenantMismatchException('TenantStamp conflicts with the current tenant context.');
            }
        }

        if ([] === $stamps) {
            $envelope = $envelope->with(new TenantStamp($tenantId));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
