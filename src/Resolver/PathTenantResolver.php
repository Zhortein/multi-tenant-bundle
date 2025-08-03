<?php

namespace Zhortein\MultiTenantBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class PathTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass
    ) {}

    public function resolveTenant(Request $request): ?TenantInterface
    {
        $segments = explode('/', trim($request->getPathInfo(), '/'));
        $slug = $segments[0] ?? null;

        if (empty($slug)) {
            return null;
        }

        $repo = $this->em->getRepository($this->tenantEntityClass);
        return $repo->findOneBy(['slug' => $slug]);
    }
}