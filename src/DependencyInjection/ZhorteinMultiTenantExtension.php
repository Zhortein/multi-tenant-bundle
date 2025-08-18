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
use Zhortein\MultiTenantBundle\Command\SyncRlsPoliciesCommand;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor;
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;
use Zhortein\MultiTenantBundle\Doctrine\DefaultConnectionResolver;
use Zhortein\MultiTenantBundle\Doctrine\EventAwareConnectionResolver;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\EventListener\TenantDoctrineFilterListener;
use Zhortein\MultiTenantBundle\EventListener\TenantEntityListener;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestListener;
use Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerTransportFactory;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManagerInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportFactory;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Registry\DoctrineTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;
use Zhortein\MultiTenantBundle\Resolver\PathTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\QueryTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;
use Zhortein\MultiTenantBundle\Storage\S3Storage;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

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

        // Register tenant-aware services
        $this->registerTenantAwareServices($container, $config);

        // Register decorators
        $this->registerDecorators($container, $config);

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
        $container->setParameter('zhortein_multi_tenant.query.parameter', $config['query']['parameter']);
        $container->setParameter('zhortein_multi_tenant.resolver_chain.order', $config['resolver_chain']['order']);
        $container->setParameter('zhortein_multi_tenant.resolver_chain.strict', $config['resolver_chain']['strict']);
        $container->setParameter('zhortein_multi_tenant.resolver_chain.header_allow_list', $config['resolver_chain']['header_allow_list']);
        $container->setParameter('zhortein_multi_tenant.domain.domain_mapping', $config['domain']['domain_mapping']);
        $container->setParameter('zhortein_multi_tenant.hybrid.domain_mapping', $config['hybrid']['domain_mapping']);
        $container->setParameter('zhortein_multi_tenant.hybrid.subdomain_mapping', $config['hybrid']['subdomain_mapping']);
        $container->setParameter('zhortein_multi_tenant.hybrid.excluded_subdomains', $config['hybrid']['excluded_subdomains']);
        $container->setParameter('zhortein_multi_tenant.dns_txt.timeout', $config['dns_txt']['timeout']);
        $container->setParameter('zhortein_multi_tenant.dns_txt.enable_cache', $config['dns_txt']['enable_cache']);
        $container->setParameter('zhortein_multi_tenant.database.strategy', $config['database']['strategy']);
        $container->setParameter('zhortein_multi_tenant.database.enable_filter', $config['database']['enable_filter']);
        $container->setParameter('zhortein_multi_tenant.database.rls.enabled', $config['database']['rls']['enabled']);
        $container->setParameter('zhortein_multi_tenant.database.rls.session_variable', $config['database']['rls']['session_variable']);
        $container->setParameter('zhortein_multi_tenant.database.rls.policy_name_prefix', $config['database']['rls']['policy_name_prefix']);
        $container->setParameter('zhortein_multi_tenant.cache.pool', $config['cache']['pool']);
        $container->setParameter('zhortein_multi_tenant.cache.ttl', $config['cache']['ttl']);
        $container->setParameter('zhortein_multi_tenant.mailer.enabled', $config['mailer']['enabled']);
        $container->setParameter('zhortein_multi_tenant.messenger.enabled', $config['messenger']['enabled']);
        $container->setParameter('zhortein_multi_tenant.messenger.default_transport', $config['messenger']['default_transport']);
        $container->setParameter('zhortein_multi_tenant.messenger.add_tenant_headers', $config['messenger']['add_tenant_headers']);
        $container->setParameter('zhortein_multi_tenant.messenger.tenant_transport_map', $config['messenger']['tenant_transport_map']);
        $container->setParameter('zhortein_multi_tenant.fixtures.enabled', $config['fixtures']['enabled']);
        $container->setParameter('zhortein_multi_tenant.events.dispatch_database_switch', $config['events']['dispatch_database_switch']);
        $container->setParameter('zhortein_multi_tenant.container.enable_tenant_scope', $config['container']['enable_tenant_scope']);
        $container->setParameter('zhortein_multi_tenant.decorators.cache.enabled', $config['decorators']['cache']['enabled']);
        $container->setParameter('zhortein_multi_tenant.decorators.cache.services', $config['decorators']['cache']['services']);
        $container->setParameter('zhortein_multi_tenant.decorators.logger.enabled', $config['decorators']['logger']['enabled']);
        $container->setParameter('zhortein_multi_tenant.decorators.logger.channels', $config['decorators']['logger']['channels']);
        $container->setParameter('zhortein_multi_tenant.decorators.storage.enabled', $config['decorators']['storage']['enabled']);
        $container->setParameter('zhortein_multi_tenant.decorators.storage.use_slug', $config['decorators']['storage']['use_slug']);
        $container->setParameter('zhortein_multi_tenant.decorators.storage.path_separator', $config['decorators']['storage']['path_separator']);
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
            ->setArgument('$cache', new Reference($config['cache']['pool']));

        $container->setAlias(TenantSettingsManagerInterface::class, TenantSettingsManager::class);

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
            ->setAutoconfigured(true)
            ->setArgument('$ormConfiguration', new Reference('doctrine.orm.default_configuration'));

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
                    ->setAutoconfigured(true)
                    ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%');

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

            case 'query':
                $container->register(QueryTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$parameterName', $config['query']['parameter']);

                $container->setAlias(TenantResolverInterface::class, QueryTenantResolver::class);
                break;

            case 'domain':
                $container->register(DomainBasedTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$domainMapping', $config['domain']['domain_mapping']);

                $container->setAlias(TenantResolverInterface::class, DomainBasedTenantResolver::class);
                break;

            case 'hybrid':
                $container->register(HybridDomainSubdomainResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$domainMapping', $config['hybrid']['domain_mapping'])
                    ->setArgument('$subdomainMapping', $config['hybrid']['subdomain_mapping'])
                    ->setArgument('$excludedSubdomains', $config['hybrid']['excluded_subdomains']);

                $container->setAlias(TenantResolverInterface::class, HybridDomainSubdomainResolver::class);
                break;

            case 'dns_txt':
                $container->register(DnsTxtTenantResolver::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setArgument('$dnsTimeout', $config['dns_txt']['timeout'])
                    ->setArgument('$enableCache', $config['dns_txt']['enable_cache']);

                $container->setAlias(TenantResolverInterface::class, DnsTxtTenantResolver::class);
                break;

            case 'chain':
                $this->registerChainResolver($container, $config);
                break;

            case 'custom':
                // For custom resolvers, the user must register their own implementation
                // and alias it to TenantResolverInterface
                break;
        }
    }

    /**
     * Registers the chain resolver with all individual resolvers.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerChainResolver(ContainerBuilder $container, array $config): void
    {
        // Register all individual resolvers
        $resolverServices = [];

        // Path resolver
        $container->register('zhortein_multi_tenant.resolver.path', PathTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%');
        $resolverServices['path'] = new Reference('zhortein_multi_tenant.resolver.path');

        // Subdomain resolver
        $container->register('zhortein_multi_tenant.resolver.subdomain', SubdomainTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$baseDomain', $config['subdomain']['base_domain'])
            ->setArgument('$excludedSubdomains', $config['subdomain']['excluded_subdomains']);
        $resolverServices['subdomain'] = new Reference('zhortein_multi_tenant.resolver.subdomain');

        // Header resolver
        $container->register('zhortein_multi_tenant.resolver.header', HeaderTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$headerName', $config['header']['name']);
        $resolverServices['header'] = new Reference('zhortein_multi_tenant.resolver.header');

        // Query resolver
        $container->register('zhortein_multi_tenant.resolver.query', QueryTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$parameterName', $config['query']['parameter']);
        $resolverServices['query'] = new Reference('zhortein_multi_tenant.resolver.query');

        // Domain resolver
        $container->register('zhortein_multi_tenant.resolver.domain', DomainBasedTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$domainMapping', $config['domain']['domain_mapping']);
        $resolverServices['domain'] = new Reference('zhortein_multi_tenant.resolver.domain');

        // Hybrid resolver
        $container->register('zhortein_multi_tenant.resolver.hybrid', HybridDomainSubdomainResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$domainMapping', $config['hybrid']['domain_mapping'])
            ->setArgument('$subdomainMapping', $config['hybrid']['subdomain_mapping'])
            ->setArgument('$excludedSubdomains', $config['hybrid']['excluded_subdomains']);
        $resolverServices['hybrid'] = new Reference('zhortein_multi_tenant.resolver.hybrid');

        // DNS TXT resolver
        $container->register('zhortein_multi_tenant.resolver.dns_txt', DnsTxtTenantResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$dnsTimeout', $config['dns_txt']['timeout'])
            ->setArgument('$enableCache', $config['dns_txt']['enable_cache']);
        $resolverServices['dns_txt'] = new Reference('zhortein_multi_tenant.resolver.dns_txt');

        // Register the chain resolver
        $container->register(ChainTenantResolver::class)
            ->setArgument('$resolvers', $resolverServices)
            ->setArgument('$order', $config['resolver_chain']['order'])
            ->setArgument('$strict', $config['resolver_chain']['strict'])
            ->setArgument('$headerAllowList', $config['resolver_chain']['header_allow_list'])
            ->setArgument('$logger', new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE));

        $container->setAlias(TenantResolverInterface::class, ChainTenantResolver::class);
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

        // Register tenant resolution exception listener
        $container->register(TenantResolutionExceptionListener::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$environment', '%kernel.environment%')
            ->setArgument('$logger', new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE));
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
            ->addTag('console.command');

        // Tenant migration command
        $container->register(MigrateTenantsCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$migrationConfiguration', new Reference('doctrine.migrations.configuration'))
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

        // RLS sync command (if RLS is enabled)
        if ($config['database']['rls']['enabled']) {
            $container->register(SyncRlsPoliciesCommand::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$sessionVariable', '%zhortein_multi_tenant.database.rls.session_variable%')
                ->setArgument('$policyNamePrefix', '%zhortein_multi_tenant.database.rls.policy_name_prefix%')
                ->addTag('console.command');
        }
    }

    /**
     * Registers tenant-aware services.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerTenantAwareServices(ContainerBuilder $container, array $config): void
    {
        // Register mailer services
        if ($config['mailer']['enabled']) {
            $this->registerMailerServices($container);
        }

        // Register messenger services
        if ($config['messenger']['enabled']) {
            $this->registerMessengerServices($container);
        }

        // Register storage services
        if ($config['storage']['enabled']) {
            $this->registerStorageServices($container, $config);
        }

        // Register RLS services
        if ($config['database']['rls']['enabled']) {
            $this->registerRlsServices($container, $config);
        }

        // Register entity listener
        $this->registerEntityListener($container);
    }

    /**
     * Registers mailer services.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function registerMailerServices(ContainerBuilder $container): void
    {
        // Only register mailer services if Symfony Mailer is available
        if (!class_exists('Symfony\Component\Mailer\MailerInterface')) {
            return;
        }

        $container->register('zhortein_multi_tenant.mailer.configurator', TenantMailerConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register('zhortein_multi_tenant.mailer.transport_factory', TenantMailerTransportFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('mailer.transport_factory');

        $container->register('zhortein_multi_tenant.mailer.tenant_aware', TenantAwareMailer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }

    /**
     * Registers messenger services.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function registerMessengerServices(ContainerBuilder $container): void
    {
        // Only register messenger services if Symfony Messenger is available
        if (!class_exists('Symfony\Component\Messenger\MessageBusInterface')) {
            return;
        }

        $container->register('zhortein_multi_tenant.messenger.configurator', TenantMessengerConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register('zhortein_multi_tenant.messenger.transport_factory', TenantMessengerTransportFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('messenger.transport_factory');

        // Register transport resolver middleware
        $container->register('zhortein_multi_tenant.messenger.transport_resolver', TenantMessengerTransportResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantTransportMap', '%zhortein_multi_tenant.messenger.tenant_transport_map%')
            ->setArgument('$defaultTransport', '%zhortein_multi_tenant.messenger.default_transport%')
            ->setArgument('$addTenantHeaders', '%zhortein_multi_tenant.messenger.add_tenant_headers%')
            ->addTag('messenger.middleware', ['priority' => 100]);
    }

    /**
     * Registers storage services.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerStorageServices(ContainerBuilder $container, array $config): void
    {
        $storageType = $config['storage']['type'];

        if ('local' === $storageType) {
            $container->register('zhortein_multi_tenant.storage', LocalStorage::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$basePath', $config['storage']['local']['base_path'])
                ->setArgument('$baseUrl', $config['storage']['local']['base_url']);
        } elseif ('s3' === $storageType) {
            $container->register('zhortein_multi_tenant.storage', S3Storage::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$bucket', $config['storage']['s3']['bucket'])
                ->setArgument('$region', $config['storage']['s3']['region'])
                ->setArgument('$baseUrl', $config['storage']['s3']['base_url']);
        }

        // Register the interface alias
        $container->setAlias(TenantFileStorageInterface::class, 'zhortein_multi_tenant.storage');
    }

    /**
     * Registers RLS services.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerRlsServices(ContainerBuilder $container, array $config): void
    {
        // Only register RLS services for shared_db strategy
        if ('shared_db' !== $config['database']['strategy']) {
            return;
        }

        $container->register(TenantSessionConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$rlsEnabled', '%zhortein_multi_tenant.database.rls.enabled%')
            ->setArgument('$sessionVariable', '%zhortein_multi_tenant.database.rls.session_variable%')
            ->addTag('kernel.event_listener')
            ->addTag('messenger.middleware');
    }

    /**
     * Registers the tenant entity listener.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function registerEntityListener(ContainerBuilder $container): void
    {
        $container->register('zhortein_multi_tenant.entity_listener', TenantEntityListener::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }

    /**
     * Registers tenant-aware decorators.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerDecorators(ContainerBuilder $container, array $config): void
    {
        // Register storage path helper
        if ($config['decorators']['storage']['enabled']) {
            $container->register(TenantStoragePathHelper::class)
                ->setAutowired(true)
                ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.storage.enabled%')
                ->setArgument('$pathSeparator', '%zhortein_multi_tenant.decorators.storage.path_separator%');
        }

        // Register logger processor
        if ($config['decorators']['logger']['enabled']) {
            $container->register('zhortein_multi_tenant.logger_processor', TenantLoggerProcessor::class)
                ->setAutowired(true)
                ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.logger.enabled%')
                ->addTag('monolog.processor');
        }

        // Register cache decorators
        if ($config['decorators']['cache']['enabled']) {
            foreach ($config['decorators']['cache']['services'] as $serviceId) {
                // Register PSR-6 cache decorator
                $container->register($serviceId.'.tenant_aware', TenantAwareCacheDecorator::class)
                    ->setDecoratedService($serviceId)
                    ->setAutowired(true)
                    ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.cache.enabled%');

                // Register PSR-16 simple cache decorator
                $container->register($serviceId.'.simple.tenant_aware', TenantAwareSimpleCacheDecorator::class)
                    ->setDecoratedService($serviceId.'.simple', null, 1) // Lower priority to avoid conflicts
                    ->setAutowired(true)
                    ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.cache.enabled%');
            }
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
        } catch (\Exception) {
            // Services file is optional, continue without it
        }
    }
}
