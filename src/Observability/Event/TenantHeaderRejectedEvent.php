<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a tenant header is rejected.
 *
 * This event is fired when a header-based tenant resolver
 * rejects a header that is not in the configured allow-list.
 */
final class TenantHeaderRejectedEvent extends Event
{
    public function __construct(
        private readonly string $headerName,
    ) {
    }

    /**
     * Gets the name of the rejected header.
     */
    public function getHeaderName(): string
    {
        return $this->headerName;
    }
}