<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\AddDoctrineFilterCompilerPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\AutoTagTenantAwareEntitiesPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ComposeTenantMessengerPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ConditionalCacheDecoratorsPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ConfigureTenantEntityManagersPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ValidateMultiDatabaseConfigurationPass;

/**
 * Multi-tenant bundle for Symfony applications.
 *
 * This bundle provides comprehensive multi-tenancy support including:
 * - Tenant resolution from requests (subdomain, path, custom)
 * - Tenant-aware database filtering with Doctrine
 * - Tenant-specific settings management with caching
 * - Event listeners for automatic tenant context setup
 * - Console commands for tenant management
 * - Middleware for explicit tenant resolution control
 */
final class ZhorteinMultiTenantBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Add compiler passes for automatic configuration
        $container->addCompilerPass(new AddDoctrineFilterCompilerPass());
        $container->addCompilerPass(new ConfigureTenantEntityManagersPass());
        $container->addCompilerPass(new AutoTagTenantAwareEntitiesPass());
        $container->addCompilerPass(new ConditionalCacheDecoratorsPass());
        $container->addCompilerPass(new ValidateMultiDatabaseConfigurationPass());
        $container->addCompilerPass(new ComposeTenantMessengerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
