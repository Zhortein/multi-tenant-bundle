<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

/**
 * Prepares reversible connection transitions without exposing DBAL details.
 */
interface TenantConnectionLifecycleInterface
{
    public function prepare(TenantConnectionState $current, TenantConnectionState $target): TenantConnectionTransitionInterface;
}
