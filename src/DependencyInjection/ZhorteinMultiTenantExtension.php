<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Command\ClearTenantSettingsCacheCommand;
use Zhortein\MultiTenantBundle\Command\CreateTenantCommand;
use Zhortein\MultiTenantBundle\Command\CreateTenantSchemaCommand;
use Zhortein\MultiTenantBundle\Command\DropTenantSchemaCommand;
use Zhortein\MultiTenantBundle\Command\ListTenantsCommand;
use Zhortein\MultiTenantBundle\Command\LoadTenantFixturesCommand;
use Zhortein\MultiTenantBundle\Command\MigrateTenantsCommand;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\DefaultConnectionResolver;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantDoctrineFilterListener;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestListener;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Registry\DoctrineTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Doctrine\EventAwareConnectionResolver;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\DependencyInjection\TenantScope;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\PathTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * Extension class for the multi-tenant bundle.
 *
 * This class handles the configuration and registration of all bundle services,
 * including tenant resolvers, context managers, event listeners, and commands.
 */
final class ZhorteinMultiTenantExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set configuration parameters
        $this->setConfigurationParameters($container, $config);

        // Register core services
        $this->registerCoreServices($container, $config);

        // Register tenant resolver
        $this->registerTenantResolver($container, $config);

        // Register event listeners
        $this->registerEventListeners($container, $config);

        // Register commands
        $this->registerCommands($container, $config);

        // Load service definitions from YAML
        $this->loadServiceDefinitions($container);
    }

    /**
     * Sets configuration parameters in the container.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function setConfigurationParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('zhortein_multi_tenant.tenant_entity', $config['tenant_entity']);
        $container->setParameter('zhortein_multi_tenant.resolver_type', $config['resolver']);
        $container->setParameter('zhortein_multi_tenant.default_tenant', $config['default_tenant']);
        $container->setParameter('zhortein_multi_tenant.require_tenant', $config['require_tenant']);
        $container->setParameter('zhortein_multi_tenant.subdomain.base_domain', $config['subdomain']['base_domain']);
        $container->setParameter('zhortein_multi_tenant.subdomain.excluded_subdomains', $config['subdomain']['excluded_subdomains']);
        $container->setParameter('zhortein_multi_tenant.header.name', $config['header']['name']);
        $container->setParameter('zhortein_multi_tenant.database.strategy', $config['database']['strategy']);
        $container->setParameter('zhortein_multi_tenant.database.enable_filter', $config['database']['enable_filter']);
        $container->setParameter('zhortein_multi_tenant.cache.pool', $config['cache']['pool']);
        $container->setParameter('zhortein_multi_tenant.cache.ttl', $config['cache']['ttl']);
        $container->setParameter('zhortein_multi_tenant.mailer.enabled', $config['mailer']['enabled']);
        $container->setParameter('zhortein_multi_tenant.messenger.enabled', $config['messenger']['enabled']);
        $container->setParameter('zhortein_multi_tenant.fixtures.enabled', $config['fixtures']['enabled']);
        $container->setParameter('zhortein_multi_tenant.events.dispatch_database_switch', $config['events']['dispatch_database_switch']);
        $container->setParameter('zhortein_multi_tenant.container.enable_tenant_scope', $config['container']['enable_tenant_scope']);
    }

    /**
     * Registers core services.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerCoreServices(ContainerBuilder $container, array $config): void
    {
        // Register tenant context
        $container->register(TenantContext::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->setAlias(TenantContextInterface::class, TenantContext::class);

        // Register tenant registry
        $container->register(DoctrineTenantRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%');

        $container->setAlias(TenantRegistryInterface::class, DoctrineTenantRegistry::class);

        // Register tenant settings manager
        $container->register(TenantSettingsManager::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$cache', new Reference($config['cache']['pool']))
            ->setArgument('$cacheTtl', $config['cache']['ttl']);

        // Register connection resolver
        $container->register(DefaultConnectionResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        // Register event-aware connection resolver if events are enabled
        if ($config['events']['dispatch_database_switch']) {
            $container->register(EventAwareConnectionResolver::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$innerResolver', new Reference(DefaultConnectionResolver::class));

            $container->setAlias(TenantConnectionResolverInterface::class, EventAwareConnectionResolver::class);
        } else {
            $container->setAlias(TenantConnectionResolverInterface::class, DefaultConnectionResolver::class);
        }

        // Register tenant entity manager factory
        $container->register(TenantEntityManagerFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        // Register tenant scope if enabled
        if ($config['container']['enable_tenant_scope']) {
            $container->register(TenantScope::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }
    }

    /**
     * Registers the appropriate tenant resolver based on configuration.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerTenantResolver(ContainerBuilder $container, array $config): void
    {
        switch ($config['resolver']) {
            case 'path':
                $container->register(PathTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);

                $container->setAlias(TenantResolverInterface::class, PathTenantResolver::class);
                break;

            case 'subdomain':
                $container->register(SubdomainTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$baseDomain', $config['subdomain']['base_domain'])
                    ->setArgument('$excludedSubdomains', $config['subdomain']['excluded_subdomains']);

                $container->setAlias(TenantResolverInterface::class, SubdomainTenantResolver::class);
                break;

            case 'header':
                $container->register(HeaderTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$headerName', $config['header']['name']);

                $container->setAlias(TenantResolverInterface::class, HeaderTenantResolver::class);
                break;

            case 'custom':
                // For custom resolvers, the user must register their own implementation
                // and alias it to TenantResolverInterface
                break;
        }
    }

    /**
     * Registers event listeners based on configuration.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerEventListeners(ContainerBuilder $container, array $config): void
    {
        if ($config['listeners']['request_listener']) {
            $container->register(TenantRequestListener::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($config['listeners']['doctrine_filter_listener'] && $config['database']['enable_filter']) {
            $container->register(TenantDoctrineFilterListener::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }
    }

    /**
     * Registers console commands.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        // List tenants command
        $container->register(ListTenantsCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%')
            ->addTag('console.command');

        // Create tenant command
        $container->register(CreateTenantCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%')
            ->addTag('console.command');

        // Clear tenant settings cache command
        $container->register(ClearTenantSettingsCacheCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$cache', new Reference($config['cache']['pool']))
            ->addTag('console.command');

        // Tenant migration command
        $container->register(MigrateTenantsCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        // Tenant schema creation command
        $container->register(CreateTenantSchemaCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        // Tenant schema drop command
        $container->register(DropTenantSchemaCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        // Tenant fixtures command (if fixtures are enabled)
        if ($config['fixtures']['enabled']) {
            $container->register(LoadTenantFixturesCommand::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }
    }

    /**
     * Loads service definitions from YAML files.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function loadServiceDefinitions(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        try {
            $loader->load('services.yaml');
        } catch (\Exception $exception) {
            // Services file is optional, continue without it
        }
    }
}
