<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Middleware that resolves tenant-specific message transports.
 *
 * This middleware automatically adds transport names based on the current tenant
 * and can tag messages with tenant information for proper routing.
 */
final class TenantMessengerTransportResolver implements MiddlewareInterface
{
    /**
     * @param TenantContextInterface   $tenantContext      The tenant context service
     * @param array<string, string>    $tenantTransportMap Mapping of tenant slugs to transport names
     * @param string                   $defaultTransport   Default transport when no tenant-specific mapping exists
     * @param bool                     $addTenantHeaders   Whether to add tenant information to message headers
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly array $tenantTransportMap = [],
        private readonly string $defaultTransport = 'async',
        private readonly bool $addTenantHeaders = true,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenant = $this->tenantContext->getTenant();
        
        // Add tenant-specific transport if configured
        if ($tenant !== null && !$envelope->last(TransportNamesStamp::class)) {
            $transportName = $this->resolveTransportName($tenant->getSlug());
            $envelope = $envelope->with(new TransportNamesStamp([$transportName]));
        }
        
        // Add tenant information as stamps/headers if enabled
        if ($this->addTenantHeaders && $tenant !== null) {
            $envelope = $this->addTenantStamps($envelope, $tenant);
        }
        
        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * Resolves the transport name for a given tenant slug.
     *
     * @param string $tenantSlug The tenant slug
     *
     * @return string The transport name to use
     */
    private function resolveTransportName(string $tenantSlug): string
    {
        return $this->tenantTransportMap[$tenantSlug] ?? $this->defaultTransport;
    }

    /**
     * Adds tenant information as stamps to the envelope.
     *
     * @param Envelope $envelope The message envelope
     * @param object   $tenant   The tenant object
     *
     * @return Envelope The envelope with tenant stamps
     */
    private function addTenantStamps(Envelope $envelope, object $tenant): Envelope
    {
        // Add tenant information as a custom stamp
        return $envelope->with(new TenantStamp($tenant->getSlug(), $tenant->getName()));
    }
}