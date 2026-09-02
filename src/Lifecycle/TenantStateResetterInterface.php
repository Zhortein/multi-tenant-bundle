<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Lifecycle;

use Symfony\Contracts\Service\ResetInterface;

interface TenantStateResetterInterface extends ResetInterface
{
    /** Reset the complete process-local tenant state to NONE. */
    public function reset(): void;
}
