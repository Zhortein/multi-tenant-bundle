<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Symfony\Contracts\Service\ResetInterface;

/** @internal Shared-database lifecycle; the active connection never changes. */
final class NoOpTenantConnectionLifecycle implements TenantConnectionLifecycleInterface, ResetInterface
{
    public function prepare(TenantConnectionState $current, TenantConnectionState $target): TenantConnectionTransitionInterface
    {
        return new class implements TenantConnectionTransitionInterface {
            public function activate(): void
            {
            }

            public function restore(): void
            {
            }

            public function cleanup(): void
            {
            }
        };
    }

    public function reset(): void
    {
    }
}
