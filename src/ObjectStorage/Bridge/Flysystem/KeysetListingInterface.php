<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;

/** Explicit bounded, ordered listing capability for the same target as the operator. */
interface KeysetListingInterface
{
    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage;
}
