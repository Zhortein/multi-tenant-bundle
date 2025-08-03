<?php

namespace Zhortein\MultiTenantBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class SubdomainTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tenantEntityClass,
        private readonly string $baseDomain // Ex: "services-locaux.fr"
    ) {}

    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();

        if (!str_ends_with($host, $this->baseDomain)) {
            return null;
        }

        $subdomain = str_replace('.' . $this->baseDomain, '', $host);
        if ($subdomain === 'www' || $subdomain === $this->baseDomain) {
            return null;
        }

        $repo = $this->em->getRepository($this->tenantEntityClass);
        return $repo->findOneBy(['slug' => $subdomain]);
    }
}