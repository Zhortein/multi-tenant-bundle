<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\DriverManager;

/**
 * Supplies complete, non-secretly-loggable DBAL parameters for a prepared state.
 *
 * Implementations must explicitly support GLOBAL and NONE; neither may fall
 * back to parameters from the last tenant.
 */
/** @phpstan-import-type Params from DriverManager */
interface TenantConnectionParametersProviderInterface
{
    /** @phpstan-return Params */
    public function parametersFor(TenantConnectionState $state): array;
}
