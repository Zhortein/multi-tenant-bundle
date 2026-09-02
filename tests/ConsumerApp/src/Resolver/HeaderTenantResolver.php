<?php

declare(strict_types=1);

namespace App\Resolver;

use App\Entity\Tenant;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

final class HeaderTenantResolver implements TenantResolverInterface
{
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $tenantId = $request->headers->get('X-Consumer-Tenant');

        if ('throw' === $tenantId) {
            throw new \RuntimeException('Expected consumer resolver failure.');
        }

        return null === $tenantId || '' === $tenantId ? null : new Tenant($tenantId, $tenantId);
    }
}
