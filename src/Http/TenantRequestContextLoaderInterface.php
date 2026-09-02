<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantRequestContextLoaderInterface
{
    /**
     * Resolves and synchronizes a request from a guaranteed NONE state.
     * A null result or any exception leaves the context at NONE.
     */
    public function load(Request $request): ?TenantInterface;
}
