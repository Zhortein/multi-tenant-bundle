<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Resolves tenant from HTTP headers.
 *
 * This resolver extracts tenant information from HTTP headers,
 * useful for API-based applications or when using custom headers.
 */
final readonly class HeaderTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private string $headerName = 'X-Tenant-Slug',
    ) {
    }

    public function resolveTenant(Request $request): ?TenantInterface
    {
        $tenantSlug = $request->headers->get($this->headerName);

        if (null === $tenantSlug || '' === $tenantSlug) {
            return null;
        }

        try {
            return $this->tenantRegistry->getBySlug($tenantSlug);
        } catch (TenantNotFoundException) {
            return null;
        }
    }

    /**
     * Gets the header name used for tenant resolution.
     */
    public function getHeaderName(): string
    {
        return $this->headerName;
    }
}
