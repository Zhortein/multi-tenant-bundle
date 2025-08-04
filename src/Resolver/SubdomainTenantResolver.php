<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Resolves tenants based on subdomain in the request host.
 *
 * Example: tenant-slug.example.com -> resolves to tenant with slug "tenant-slug"
 * Excludes common subdomains like "www" and the base domain itself.
 */
final class SubdomainTenantResolver implements TenantResolverInterface
{
    /** @var string[] Common subdomains to exclude from tenant resolution */
    private const EXCLUDED_SUBDOMAINS = ['www', 'api', 'admin', 'mail', 'ftp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
        private readonly string $baseDomain,
    ) {
    }

    /**
     * Resolves tenant from subdomain.
     *
     * @param Request $request The HTTP request
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();

        if (!str_ends_with($host, $this->baseDomain)) {
            return null;
        }

        $subdomain = str_replace('.'.$this->baseDomain, '', $host);

        // Skip if it's the base domain itself or an excluded subdomain
        if ($subdomain === $this->baseDomain || in_array($subdomain, self::EXCLUDED_SUBDOMAINS, true)) {
            return null;
        }

        // Skip if subdomain contains dots (nested subdomains)
        if (str_contains($subdomain, '.')) {
            return null;
        }

        $repository = $this->em->getRepository($this->tenantEntityClass);

        /** @var TenantInterface|null $tenant */
        $tenant = $repository->findOneBy(['slug' => $subdomain]);

        return $tenant;
    }
}
