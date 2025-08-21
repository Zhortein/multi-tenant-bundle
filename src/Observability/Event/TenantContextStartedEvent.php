<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a tenant context is started.
 *
 * This event is fired when the tenant context is initialized
 * with a specific tenant for the current request lifecycle.
 */
final class TenantContextStartedEvent extends Event
{
    public function __construct(
        private readonly string $tenantId,
    ) {
    }

    /**
     * Gets the ID of the tenant for which the context was started.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
}
