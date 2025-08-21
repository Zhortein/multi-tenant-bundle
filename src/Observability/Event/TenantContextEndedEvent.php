<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a tenant context is ended.
 *
 * This event is fired when the tenant context is cleared
 * or when switching from one tenant to another.
 */
final class TenantContextEndedEvent extends Event
{
    public function __construct(
        private readonly ?string $tenantId,
    ) {
    }

    /**
     * Gets the ID of the tenant for which the context was ended.
     * Returns null if no tenant was active.
     */
    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}
