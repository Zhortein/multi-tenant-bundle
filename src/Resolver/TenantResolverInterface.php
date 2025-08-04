<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Interface for tenant resolution strategies.
 *
 * Implementations should extract tenant information from HTTP requests
 * using different strategies (subdomain, path, header, etc.).
 */
interface TenantResolverInterface
{
    /**
     * Attempts to resolve a tenant from the HTTP request.
     *
     * @param Request $request The HTTP request to analyze
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface;
}
