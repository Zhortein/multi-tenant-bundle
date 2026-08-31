<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

interface TenantConnectionTransitionInterface
{
    public function activate(): void;

    public function restore(): void;

    public function cleanup(): void;
}
