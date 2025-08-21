<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when Row-Level Security (RLS) is applied for a tenant.
 *
 * This event is fired when PostgreSQL session variables are configured
 * for RLS policies, indicating success or failure of the operation.
 */
final class TenantRlsAppliedEvent extends Event
{
    public function __construct(
        private readonly string $tenantId,
        private readonly bool $success,
        private readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Gets the ID of the tenant for which RLS was applied.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Indicates whether the RLS application was successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Gets the error message if RLS application failed.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
