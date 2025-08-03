<?php

namespace Zhortein\MultiTenantBundle\Doctrine;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantConnectionResolverInterface
{
    /**
     * Retourne les paramètres Doctrine à utiliser pour ce tenant.
     * Exemples : dbname, user, password, host, etc.
     */
    public function resolveParameters(TenantInterface $tenant): array;
}