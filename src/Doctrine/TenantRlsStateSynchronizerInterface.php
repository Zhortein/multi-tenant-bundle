<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

interface TenantRlsStateSynchronizerInterface
{
    public function apply(TenantConnectionState $state): void;
}
