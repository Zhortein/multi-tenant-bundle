<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Resolves tenants based on the full domain name.
 *
 * This resolver matches the complete host from the request against a configured
 * domain mapping to determine the tenant. For example:
 * - tenant-one.com -> tenant_one
 * - acme.org -> acme
 *
 * @phpstan-type DomainMapping array<string, string>
 */
final readonly class DomainBasedTenantResolver implements TenantResolverInterface
{
    /**
     * @param TenantRegistryInterface $tenantRegistry The tenant registry
     * @param DomainMapping           $domainMapping  Mapping of domains to tenant slugs
     */
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private array                   $domainMapping = [],
    ) {
    }

    /**
     * Resolves tenant from the full domain name.
     *
     * @param Request $request The HTTP request
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();

        // Normalize host (remove port if present)
        $host = $this->normalizeHost($host);

        // Check if the domain is mapped to a tenant
        if (!isset($this->domainMapping[$host])) {
            return null;
        }

        $tenantSlug = $this->domainMapping[$host];

        try {
            return $this->tenantRegistry->getBySlug($tenantSlug);
        } catch (\Exception) {
            // Tenant not found in registry
            return null;
        }
    }

    /**
     * Normalizes the host by removing port information.
     *
     * @param string $host The raw host from the request
     *
     * @return string The normalized host
     */
    private function normalizeHost(string $host): string
    {
        // Remove port if present (e.g., "example.com:8080" -> "example.com")
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        return strtolower(trim($host));
    }

    /**
     * Gets the configured domain mapping.
     *
     * @return DomainMapping The domain to tenant slug mapping
     */
    public function getDomainMapping(): array
    {
        return $this->domainMapping;
    }

    /**
     * Checks if a domain is configured for tenant resolution.
     *
     * @param string $domain The domain to check
     *
     * @return bool True if the domain is mapped to a tenant
     */
    public function isDomainMapped(string $domain): bool
    {
        $normalizedDomain = $this->normalizeHost($domain);

        return isset($this->domainMapping[$normalizedDomain]);
    }

    /**
     * Gets the tenant slug for a given domain.
     *
     * @param string $domain The domain to look up
     *
     * @return string|null The tenant slug or null if not mapped
     */
    public function getTenantSlugForDomain(string $domain): ?string
    {
        $normalizedDomain = $this->normalizeHost($domain);

        return $this->domainMapping[$normalizedDomain] ?? null;
    }
}
