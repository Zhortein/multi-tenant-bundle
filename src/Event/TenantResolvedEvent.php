<?php

namespace Zhortein\MultiTenantBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class TenantResolvedEvent extends Event
{
    public function __construct(
        private readonly TenantInterface $tenant,
    ) {
    }

    public function getTenant(): TenantInterface
    {
        return $this->tenant;
    }
}
