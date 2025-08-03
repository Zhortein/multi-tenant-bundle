<?php

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantResolverInterface
{
    /**
     * Attempt to resolve a tenant from the request.
     *
     * @return TenantInterface|null
     */
    public function resolveTenant(Request $request): ?TenantInterface;
}