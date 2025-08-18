<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Stamp that carries tenant information with a message.
 *
 * This stamp allows message handlers to access tenant context
 * even when processing messages asynchronously.
 */
final readonly class TenantStamp implements StampInterface
{
    public function __construct(
        private string $tenantId,
    ) {
    }

    /**
     * Gets the tenant ID.
     *
     * @return string The tenant ID
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
}
