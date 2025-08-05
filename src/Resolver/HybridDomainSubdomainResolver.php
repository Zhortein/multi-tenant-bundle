<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Resolves tenants using a hybrid approach combining domain and subdomain logic.
 *
 * This resolver first checks if the full domain matches any configured domain mapping.
 * If not found, it attempts to extract the tenant from a subdomain pattern.
 *
 * Example configuration:
 * - Domain mapping: acme-client.com -> acme, acme-platform.net -> acme
 * - Subdomain mapping: *.myplatform.com -> use subdomain as tenant slug
 *
 * @phpstan-type DomainMapping array<string, string>
 * @phpstan-type SubdomainMapping array<string, string>
 */
final class HybridDomainSubdomainResolver implements TenantResolverInterface
{
    /** @var string[] Common subdomains to exclude from tenant resolution */
    private const array EXCLUDED_SUBDOMAINS = ['www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static'];

    /** @var string Special value indicating subdomain should be used as tenant slug */
    private const string USE_SUBDOMAIN_AS_SLUG = 'use_subdomain_as_slug';

    /**
     * @param TenantRegistryInterface $tenantRegistry     The tenant registry
     * @param DomainMapping           $domainMapping      Mapping of full domains to tenant slugs
     * @param SubdomainMapping        $subdomainMapping   Mapping of domain patterns to resolution strategies
     * @param string[]                $excludedSubdomains Subdomains to exclude from tenant resolution
     */
    public function __construct(
        private readonly TenantRegistryInterface $tenantRegistry,
        private readonly array $domainMapping = [],
        private readonly array $subdomainMapping = [],
        private readonly array $excludedSubdomains = self::EXCLUDED_SUBDOMAINS,
    ) {
    }

    /**
     * Resolves tenant using hybrid domain/subdomain logic.
     *
     * @param Request $request The HTTP request
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();
        $normalizedHost = $this->normalizeHost($host);

        // Step 1: Try exact domain mapping first
        $tenant = $this->resolveTenantByDomain($normalizedHost);
        if (null !== $tenant) {
            return $tenant;
        }

        // Step 2: Try subdomain pattern matching
        return $this->resolveTenantBySubdomain($normalizedHost);
    }

    /**
     * Attempts to resolve tenant by exact domain matching.
     *
     * @param string $host The normalized host
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    private function resolveTenantByDomain(string $host): ?TenantInterface
    {
        if (!isset($this->domainMapping[$host])) {
            return null;
        }

        $tenantSlug = $this->domainMapping[$host];

        try {
            return $this->tenantRegistry->getBySlug($tenantSlug);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Attempts to resolve tenant by subdomain pattern matching.
     *
     * @param string $host The normalized host
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    private function resolveTenantBySubdomain(string $host): ?TenantInterface
    {
        foreach ($this->subdomainMapping as $pattern => $strategy) {
            if ($this->matchesPattern($host, $pattern)) {
                return $this->resolveTenantByPattern($host, $pattern, $strategy);
            }
        }

        return null;
    }

    /**
     * Checks if a host matches a subdomain pattern.
     *
     * @param string $host    The host to check
     * @param string $pattern The pattern to match (e.g., "*.myplatform.com")
     *
     * @return bool True if the host matches the pattern
     */
    private function matchesPattern(string $host, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regexPattern = str_replace(['*', '.'], ['([^.]+)', '\.'], $pattern);
        $regexPattern = '/^'.$regexPattern.'$/i';

        return 1 === preg_match($regexPattern, $host);
    }

    /**
     * Resolves tenant based on a matched pattern and strategy.
     *
     * @param string $host     The host that matched
     * @param string $pattern  The pattern that was matched
     * @param string $strategy The resolution strategy
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    private function resolveTenantByPattern(string $host, string $pattern, string $strategy): ?TenantInterface
    {
        if (self::USE_SUBDOMAIN_AS_SLUG === $strategy) {
            return $this->resolveTenantBySubdomainExtraction($host, $pattern);
        }

        // For other strategies, use the strategy value as the tenant slug
        try {
            return $this->tenantRegistry->getBySlug($strategy);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extracts subdomain and uses it as tenant slug.
     *
     * @param string $host    The host to extract from
     * @param string $pattern The pattern that was matched
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    private function resolveTenantBySubdomainExtraction(string $host, string $pattern): ?TenantInterface
    {
        // Extract the base domain from the pattern (remove the wildcard part)
        $baseDomain = str_replace('*.', '', $pattern);

        if (!str_ends_with($host, $baseDomain)) {
            return null;
        }

        // Extract the subdomain
        $subdomain = str_replace('.'.$baseDomain, '', $host);

        // Skip if it's an excluded subdomain
        if (in_array($subdomain, $this->excludedSubdomains, true)) {
            return null;
        }

        // Skip if subdomain contains dots (nested subdomains)
        if (str_contains($subdomain, '.')) {
            return null;
        }

        // Skip if subdomain is empty
        if (empty($subdomain)) {
            return null;
        }

        try {
            return $this->tenantRegistry->getBySlug($subdomain);
        } catch (\Exception) {
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
     * Gets the configured subdomain mapping.
     *
     * @return SubdomainMapping The subdomain pattern to strategy mapping
     */
    public function getSubdomainMapping(): array
    {
        return $this->subdomainMapping;
    }

    /**
     * Gets the excluded subdomains.
     *
     * @return string[] The list of excluded subdomains
     */
    public function getExcludedSubdomains(): array
    {
        return $this->excludedSubdomains;
    }

    /**
     * Checks if a domain is configured for direct domain resolution.
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
     * Checks if a host matches any subdomain pattern.
     *
     * @param string $host The host to check
     *
     * @return bool True if the host matches any subdomain pattern
     */
    public function matchesSubdomainPattern(string $host): bool
    {
        $normalizedHost = $this->normalizeHost($host);

        foreach ($this->subdomainMapping as $pattern => $strategy) {
            if ($this->matchesPattern($normalizedHost, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
