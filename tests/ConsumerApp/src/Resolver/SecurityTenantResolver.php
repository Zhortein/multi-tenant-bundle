<?php

declare(strict_types=1);

namespace App\Resolver;

use App\Entity\Tenant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

final readonly class SecurityTenantResolver implements TenantResolverInterface
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function resolveTenant(Request $request): ?TenantInterface
    {
        unset($request);
        $identifier = $this->tokenStorage->getToken()?->getUserIdentifier();

        return null === $identifier || '' === $identifier ? null : new Tenant($identifier, $identifier);
    }
}
