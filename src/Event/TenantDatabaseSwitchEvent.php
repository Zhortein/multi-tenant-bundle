<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Event dispatched when switching to a tenant's database.
 *
 * This event is fired before and after switching database connections
 * for a specific tenant, allowing listeners to perform additional setup
 * or cleanup operations.
 */
class TenantDatabaseSwitchEvent extends Event
{
    public const string BEFORE_SWITCH = 'tenant.database.before_switch';
    public const string AFTER_SWITCH = 'tenant.database.after_switch';

    /**
     * @param array<string, mixed> $connectionParameters
     */
    public function __construct(
        private readonly TenantInterface $tenant,
        private readonly array $connectionParameters,
        private readonly ?TenantInterface $previousTenant = null,
    ) {
    }

    public function getTenant(): TenantInterface
    {
        return $this->tenant;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectionParameters(): array
    {
        return $this->connectionParameters;
    }

    public function getPreviousTenant(): ?TenantInterface
    {
        return $this->previousTenant;
    }
}
