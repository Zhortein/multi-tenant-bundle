<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Resolves tenants based on the first path segment of the URL.
 *
 * Example: /tenant-slug/some/path -> resolves to tenant with slug "tenant-slug"
 */
final class PathTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
    ) {
    }

    /**
     * Resolves tenant from the first path segment.
     *
     * @param Request $request The HTTP request
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $pathInfo = $request->getPathInfo();
        $segments = explode('/', trim($pathInfo, '/'));
        $slug = $segments[0] ?? null;

        if (empty($slug)) {
            return null;
        }

        $repository = $this->em->getRepository($this->tenantEntityClass);

        /** @var TenantInterface|null $tenant */
        $tenant = $repository->findOneBy(['slug' => $slug]);

        return $tenant;
    }
}
