<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Resolves tenant from query parameters.
 *
 * This resolver extracts tenant information from query parameters,
 * useful for API-based applications or when using query strings.
 */
final readonly class QueryTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private string $parameterName = 'tenant',
    ) {
    }

    public function resolveTenant(Request $request): ?TenantInterface
    {
        $tenantSlug = $request->query->get($this->parameterName);

        if (null === $tenantSlug || '' === $tenantSlug || !\is_string($tenantSlug)) {
            return null;
        }

        try {
            return $this->tenantRegistry->getBySlug($tenantSlug);
        } catch (TenantNotFoundException) {
            return null;
        }
    }

    /**
     * Gets the parameter name used for tenant resolution.
     */
    public function getParameterName(): string
    {
        return $this->parameterName;
    }
}
