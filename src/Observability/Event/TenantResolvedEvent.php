<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a tenant is successfully resolved.
 *
 * This event is fired when any tenant resolver successfully identifies
 * a tenant from the current request context.
 */
final class TenantResolvedEvent extends Event
{
    public function __construct(
        private readonly string $resolver,
        private readonly string $tenantId,
    ) {
    }

    /**
     * Gets the name of the resolver that resolved the tenant.
     */
    public function getResolver(): string
    {
        return $this->resolver;
    }

    /**
     * Gets the ID of the resolved tenant.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
}
