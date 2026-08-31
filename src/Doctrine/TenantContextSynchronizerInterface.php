<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

interface TenantContextSynchronizerInterface
{
    public function currentState(): TenantConnectionState;

    public function transition(TenantConnectionState $current, TenantConnectionState $target): void;
}
