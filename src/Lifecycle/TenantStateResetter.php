<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Lifecycle;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final readonly class TenantStateResetter implements TenantStateResetterInterface
{
    public function __construct(private TenantContextInterface $tenantContext)
    {
    }

    public function reset(): void
    {
        $this->tenantContext->reset();
    }
}
