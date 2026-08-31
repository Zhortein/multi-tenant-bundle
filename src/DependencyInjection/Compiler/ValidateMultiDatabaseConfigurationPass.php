<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionLifecycleInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;

final class ValidateMultiDatabaseConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zhortein_multi_tenant.database.strategy')
            || 'multi_db' !== $container->getParameter('zhortein_multi_tenant.database.strategy')) {
            return;
        }

        if (!$container->hasAlias(TenantConnectionLifecycleInterface::class)
            && !$container->hasDefinition(TenantConnectionLifecycleInterface::class)) {
            throw new \LogicException('The multi_db strategy requires exactly one TenantConnectionLifecycleInterface service. Configure the Doctrine lifecycle supplied by the bundle or an equivalent reversible lifecycle.');
        }

        if (!$container->hasAlias(TenantConnectionParametersProviderInterface::class)
            && !$container->hasDefinition(TenantConnectionParametersProviderInterface::class)) {
            throw new \LogicException('The multi_db strategy requires a TenantConnectionParametersProviderInterface alias. It must explicitly provide tenant, global, and no-context connection parameters.');
        }
    }
}
