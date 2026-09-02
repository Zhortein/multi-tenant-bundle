<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Symfony\Contracts\Service\ResetInterface;

interface TenantContextSynchronizerInterface extends ResetInterface
{
    public function currentState(): TenantConnectionState;

    public function transition(TenantConnectionState $current, TenantConnectionState $target): void;

    public function reset(): void;
}
