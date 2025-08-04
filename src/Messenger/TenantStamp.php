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
final class TenantStamp implements StampInterface
{
    public function __construct(
        private readonly string $tenantSlug,
        private readonly string $tenantName,
    ) {
    }

    /**
     * Gets the tenant slug.
     *
     * @return string The tenant slug
     */
    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    /**
     * Gets the tenant name.
     *
     * @return string The tenant name
     */
    public function getTenantName(): string
    {
        return $this->tenantName;
    }
}