<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
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
use Zhortein\MultiTenantBundle\Command\TenantImpersonateCommand;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheAdapterDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor;
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionLifecycle;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionRouter;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantContextSynchronizer;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantRlsStateSynchronizer;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScope;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScopeInterface;
use Zhortein\MultiTenantBundle\Doctrine\NoOpTenantConnectionLifecycle;
use Zhortein\MultiTenantBundle\Doctrine\SharedDatabaseConnectionParametersProvider;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionLifecycleInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Doctrine\TenantRlsStateSynchronizerInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantRoutingDriverMiddleware;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantConsoleBoundarySubscriber;
use Zhortein\MultiTenantBundle\EventListener\TenantEntityListener;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestBoundaryListener;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestExceptionTracker;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestListener;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestTerminationListener;
use Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener;
use Zhortein\MultiTenantBundle\Http\TenantRequestContextLoader;
use Zhortein\MultiTenantBundle\Http\TenantRequestContextLoaderInterface;
use Zhortein\MultiTenantBundle\Lifecycle\TenantExecutionBoundary;
use Zhortein\MultiTenantBundle\Lifecycle\TenantExecutionBoundaryInterface;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetter;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerFallbackTransportFactory;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerTransportFactory;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManagerInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportFactory;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantLoggingSubscriber;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantMetricsSubscriber;
use Zhortein\MultiTenantBundle\Observability\Metrics\MetricsAdapterInterface;
use Zhortein\MultiTenantBundle\Observability\Metrics\NullMetricsAdapter;
use Zhortein\MultiTenantBundle\Registry\DoctrineTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;
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
final class ZhorteinMultiTenantExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(
            new Configuration(),
            $container->getExtensionConfig($this->getAlias()),
        );

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'filters' => [
                        'tenant_filter' => [
                            'class' => TenantDoctrineFilter::class,
                            'enabled' => true,
                        ],
                    ],
                    'resolve_target_entities' => [
                        TenantInterface::class => $config['tenant_entity'],
                    ],
                ],
            ]);
        }

        /** @var array<string, mixed> $messengerConfig */
        $messengerConfig = $config['messenger'] ?? [];
        $messengerEnabled = true === ($messengerConfig['enabled'] ?? false);
        if ($messengerEnabled && $container->hasExtension('framework')) {
            $buses = [];
            $fallbackBus = is_string($messengerConfig['fallback_bus'] ?? null)
                ? $messengerConfig['fallback_bus']
                : 'messenger.bus.default';
            foreach ($this->messengerBusNames($container, $fallbackBus) as $busName) {
                $buses[$busName] = [
                    'middleware' => [
                        TenantWorkerMiddleware::class,
                        TenantSendingMiddleware::class,
                        TenantMessengerTransportResolver::class,
                    ],
                ];
            }
            $container->prependExtensionConfig('framework', [
                'messenger' => [
                    'buses' => $buses,
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /**
         * @var array<string, mixed> $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        // Set Configuration parameters
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

        // Register observability services
        $this->registerObservabilityServices($container, $config);

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
        $mailerConfig = $config['mailer'];
        if (!is_array($mailerConfig)) {
            throw new \LogicException('The processed mailer configuration must be an array.');
        }

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
        $container->setParameter('zhortein_multi_tenant.mailer.add_tenant_id_header', $mailerConfig['add_tenant_id_header']);
        $container->setParameter('zhortein_multi_tenant.mailer.add_tenant_name_header', $mailerConfig['add_tenant_name_header']);
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
            ->setAutoconfigured(false)
            ->setArgument('$derivedStateResetters', new TaggedIteratorArgument('zhortein_multi_tenant.lifecycle_resetter'))
            ->addTag('kernel.reset', ['method' => 'reset']);

        $container->setAlias(TenantContextInterface::class, TenantContext::class);

        $container->register(TenantStateResetter::class)
            ->setArgument('$tenantContext', new Reference(TenantContextInterface::class));
        $container->setAlias(TenantStateResetterInterface::class, TenantStateResetter::class)
            ->setPublic(true);

        $container->register(TenantRequestContextLoader::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(TenantRequestContextLoaderInterface::class, TenantRequestContextLoader::class)
            ->setPublic(true);

        $container->register(TenantExecutionBoundary::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(TenantExecutionBoundaryInterface::class, TenantExecutionBoundary::class)
            ->setPublic(true);

        $container->register(DoctrineTenantContextSynchronizer::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setArgument('$managerRegistry', new Reference('doctrine', ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setLazy(true);
        $container->setAlias(TenantContextSynchronizerInterface::class, DoctrineTenantContextSynchronizer::class);

        $container->register(DoctrineTenantRlsStateSynchronizer::class)
            ->setAutowired(true)
            ->setArgument('$managerRegistry', new Reference('doctrine', ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$enabled', '%zhortein_multi_tenant.database.rls.enabled%')
            ->setArgument('$sessionVariable', '%zhortein_multi_tenant.database.rls.session_variable%');
        $container->setAlias(TenantRlsStateSynchronizerInterface::class, DoctrineTenantRlsStateSynchronizer::class);

        if ('shared_db' === $config['database']['strategy']) {
            $container->register(NoOpTenantConnectionLifecycle::class);
            $container->setAlias(TenantConnectionLifecycleInterface::class, NoOpTenantConnectionLifecycle::class);
            $container->register(SharedDatabaseConnectionParametersProvider::class)
                ->setAutowired(true);
            $container->setAlias(TenantConnectionParametersProviderInterface::class, SharedDatabaseConnectionParametersProvider::class);
        } else {
            $container->register(DoctrineTenantConnectionRouter::class)
                ->setAutowired(true);
            $container->register(TenantRoutingDriverMiddleware::class)
                ->setAutowired(true)
                ->addTag('doctrine.middleware', ['priority' => 1024]);
            $container->register(DoctrineTenantConnectionLifecycle::class)
                ->setAutowired(true);
            $container->setAlias(TenantConnectionLifecycleInterface::class, DoctrineTenantConnectionLifecycle::class);
        }

        // Register tenant registry
        $container->register(DoctrineTenantRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%');

        $container->setAlias(TenantRegistryInterface::class, DoctrineTenantRegistry::class);

        // Register tenant settings manager
        $container->register(TenantSettingRepository::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register(TenantSettingsManager::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$cache', new Reference($config['cache']['pool']));

        $container->setAlias(TenantSettingsManagerInterface::class, TenantSettingsManager::class);

        // Register tenant entity manager factory
        $container->register(TenantEntityManagerFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$ormConfiguration', new Reference('doctrine.orm.default_configuration'));

        // Register tenant scope if enabled
        if ($config['container']['enable_tenant_scope']) {
            $container->register(TenantScope::class)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->addTag('zhortein_multi_tenant.lifecycle_resetter', ['priority' => 200]);
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
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%');
        $resolverServices['path'] = new Reference('zhortein_multi_tenant.resolver.path');

        // Subdomain resolver
        $container->register('zhortein_multi_tenant.resolver.subdomain', SubdomainTenantResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantEntityClass', '%zhortein_multi_tenant.tenant_entity%')
            ->setArgument('$baseDomain', $config['subdomain']['base_domain'])
            ->setArgument('$excludedSubdomains', $config['subdomain']['excluded_subdomains']);
        $resolverServices['subdomain'] = new Reference('zhortein_multi_tenant.resolver.subdomain');

        // Header resolver
        $container->register('zhortein_multi_tenant.resolver.header', HeaderTenantResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$headerName', $config['header']['name']);
        $resolverServices['header'] = new Reference('zhortein_multi_tenant.resolver.header');

        // Query resolver
        $container->register('zhortein_multi_tenant.resolver.query', QueryTenantResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$parameterName', $config['query']['parameter']);
        $resolverServices['query'] = new Reference('zhortein_multi_tenant.resolver.query');

        // Domain resolver
        $container->register('zhortein_multi_tenant.resolver.domain', DomainBasedTenantResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$domainMapping', $config['domain']['domain_mapping']);
        $resolverServices['domain'] = new Reference('zhortein_multi_tenant.resolver.domain');

        // Hybrid resolver
        $container->register('zhortein_multi_tenant.resolver.hybrid', HybridDomainSubdomainResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$domainMapping', $config['hybrid']['domain_mapping'])
            ->setArgument('$subdomainMapping', $config['hybrid']['subdomain_mapping'])
            ->setArgument('$excludedSubdomains', $config['hybrid']['excluded_subdomains']);
        $resolverServices['hybrid'] = new Reference('zhortein_multi_tenant.resolver.hybrid');

        // DNS TXT resolver
        $container->register('zhortein_multi_tenant.resolver.dns_txt', DnsTxtTenantResolver::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$dnsTimeout', $config['dns_txt']['timeout'])
            ->setArgument('$enableCache', $config['dns_txt']['enable_cache']);
        $resolverServices['dns_txt'] = new Reference('zhortein_multi_tenant.resolver.dns_txt');

        // Register the chain resolver
        $container->register(ChainTenantResolver::class)
            ->setPublic(true)
            ->setArgument('$resolvers', $resolverServices)
            ->setArgument('$order', $config['resolver_chain']['order'])
            ->setArgument('$strict', $config['resolver_chain']['strict'])
            ->setArgument('$headerAllowList', $config['resolver_chain']['header_allow_list'])
            ->setArgument('$logger', new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE));

        $container->setAlias(TenantResolverInterface::class, ChainTenantResolver::class);
        $container->setAlias('zhortein_multi_tenant.resolver.chain', ChainTenantResolver::class)->setPublic(true);
    }

    /**
     * Registers event listeners based on configuration.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerEventListeners(ContainerBuilder $container, array $config): void
    {
        // Boundary cleanup is unconditional. Disabling automatic resolution
        // must never disable lifecycle isolation.
        $container->register(TenantRequestBoundaryListener::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->register(TenantRequestTerminationListener::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->register(TenantRequestExceptionTracker::class)
            ->setAutoconfigured(true);
        $container->register(TenantConsoleBoundarySubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        if ($config['listeners']['request_listener']) {
            $container->register(TenantRequestListener::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        // Doctrine protection is initialized by ORM configuration and synchronized
        // by TenantContext. The legacy HTTP listener is intentionally not registered.

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
            ->setArgument('$defaultConnection', new Reference(Connection::class))
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

        // Tenant impersonate command (admin-only)
        $container->register(TenantImpersonateCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$allowImpersonation', '%kernel.debug%') // Only allow in debug mode by default
            ->addTag('console.command');
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
        if (!interface_exists('Symfony\Component\Mailer\MailerInterface')) {
            return;
        }

        $container->register('zhortein_multi_tenant.mailer.configurator', TenantMailerConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(TenantMailerConfigurator::class, 'zhortein_multi_tenant.mailer.configurator');

        $container->register('zhortein_multi_tenant.mailer.fallback_transport_factory', TenantMailerFallbackTransportFactory::class)
            ->setArgument(0, new TaggedIteratorArgument(
                'mailer.transport_factory',
                exclude: ['zhortein_multi_tenant.mailer.transport_factory'],
            ));

        $container->register('zhortein_multi_tenant.mailer.transport_factory', TenantMailerTransportFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument(1, new Reference('zhortein_multi_tenant.mailer.fallback_transport_factory'))
            ->addTag('mailer.transport_factory');

        $container->register('zhortein_multi_tenant.mailer.tenant_aware', TenantAwareMailer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$addTenantIdHeader', '%zhortein_multi_tenant.mailer.add_tenant_id_header%')
            ->setArgument('$addTenantNameHeader', '%zhortein_multi_tenant.mailer.add_tenant_name_header%');
        $container->setAlias(TenantAwareMailer::class, 'zhortein_multi_tenant.mailer.tenant_aware');
    }

    /**
     * Registers messenger services.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function registerMessengerServices(ContainerBuilder $container): void
    {
        // Only register messenger services if Symfony Messenger is available
        if (!interface_exists('Symfony\Component\Messenger\MessageBusInterface')) {
            return;
        }

        $container->register('zhortein_multi_tenant.messenger.configurator', TenantMessengerConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(TenantMessengerConfigurator::class, 'zhortein_multi_tenant.messenger.configurator');

        $container->register('zhortein_multi_tenant.messenger.transport_factory', TenantMessengerTransportFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$factories', new TaggedIteratorArgument(
                'messenger.transport_factory',
                exclude: ['zhortein_multi_tenant.messenger.transport_factory'],
            ))
            ->addTag('messenger.transport_factory');

        $container->register(TenantWorkerMiddleware::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('messenger.middleware', ['priority' => 200]);

        $container->register(TenantSendingMiddleware::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('messenger.middleware', ['priority' => 150]);

        // Register transport resolver middleware
        $container->setAlias(TenantMessengerTransportFactory::class, 'zhortein_multi_tenant.messenger.transport_factory');

        $container->register('zhortein_multi_tenant.messenger.transport_resolver', TenantMessengerTransportResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$tenantTransportMap', '%zhortein_multi_tenant.messenger.tenant_transport_map%')
            ->setArgument('$defaultTransport', '%zhortein_multi_tenant.messenger.default_transport%')
            ->setArgument('$addTenantHeaders', '%zhortein_multi_tenant.messenger.add_tenant_headers%')
            ->addTag('messenger.middleware', ['priority' => 100]);
        $container->setAlias(TenantMessengerTransportResolver::class, 'zhortein_multi_tenant.messenger.transport_resolver');
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
        $databaseConfig = $config['database'] ?? null;
        if (!is_array($databaseConfig)) {
            throw new \LogicException('The processed database configuration must be an array.');
        }
        // Only register RLS services for shared_db strategy
        if ('shared_db' !== $databaseConfig['strategy']) {
            return;
        }

        $container->register(TenantSessionConfigurator::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setArgument('$rlsEnabled', '%zhortein_multi_tenant.database.rls.enabled%')
            ->setArgument('$sessionVariable', '%zhortein_multi_tenant.database.rls.session_variable%');
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

        $container->register(GlobalDoctrineScope::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setArgument('$managerRegistry', new Reference('doctrine', ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->addTag('zhortein_multi_tenant.lifecycle_resetter', ['priority' => 100]);
        $container->setAlias(GlobalDoctrineScopeInterface::class, GlobalDoctrineScope::class);
    }

    /**
     * Registers tenant-aware decorators.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     */
    private function registerDecorators(ContainerBuilder $container, array $config): void
    {
        /** @var array<string, mixed> $decoratorConfig */
        $decoratorConfig = $config['decorators'];
        /** @var array<string, mixed> $cacheDecoratorConfig */
        $cacheDecoratorConfig = $decoratorConfig['cache'];
        // Register storage path helper
        if ($config['decorators']['storage']['enabled']) {
            $container->register(TenantStoragePathHelper::class)
                ->setAutowired(true)
                ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.storage.enabled%')
                ->setArgument('$pathSeparator', '%zhortein_multi_tenant.decorators.storage.path_separator%');
        }

        // Register logger processor
        if ($config['decorators']['logger']['enabled'] && interface_exists('Monolog\Processor\ProcessorInterface')) {
            $container->register('zhortein_multi_tenant.logger_processor', TenantLoggerProcessor::class)
                ->setAutowired(true)
                ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.logger.enabled%')
                ->addTag('monolog.processor');
        }

        // Register cache decorators
        if ($cacheDecoratorConfig['enabled']) {
            foreach ($cacheDecoratorConfig['services'] as $serviceId) {
                $serviceIdString = (string) $serviceId;
                // Symfony cache pools must retain AdapterInterface for debug and traceable wrappers.
                $decoratorClass = interface_exists(AdapterInterface::class)
                    ? TenantAwareCacheAdapterDecorator::class
                    : TenantAwareCacheDecorator::class;

                $container->register($serviceIdString.'.tenant_aware', $decoratorClass)
                    ->setDecoratedService($serviceIdString)
                    ->setAutowired(true)
                    ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.cache.enabled%');

                // Register PSR-16 simple cache decorator (only if both interface and service exist)
                // Note: We skip PSR-16 decoration for now as it requires optional dependencies
                // and the .simple service may not exist in all Symfony configurations
            }
        }
    }

    /**
     * Registers observability services.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     *
     * @phpstan-param array<string, mixed> $config
     */
    private function registerObservabilityServices(ContainerBuilder $container, array $config): void
    {
        unset($config); // Config parameter not used in this method
        // Register metrics adapter (default to null adapter)
        $container->register(NullMetricsAdapter::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->setAlias(MetricsAdapterInterface::class, NullMetricsAdapter::class);

        // Register metrics subscriber
        $container->register(TenantMetricsSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.event_subscriber');

        // Register logging subscriber
        $container->register(TenantLoggingSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.event_subscriber');
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

    /** @return list<string> */
    private function messengerBusNames(ContainerBuilder $container, string $fallbackBus): array
    {
        $names = [$fallbackBus => true];
        foreach ($container->getExtensionConfig('framework') as $frameworkConfig) {
            $frameworkMessenger = $frameworkConfig['messenger'] ?? null;
            if (!is_array($frameworkMessenger)) {
                continue;
            }
            $configuredBuses = $frameworkMessenger['buses'] ?? null;
            if (!is_array($configuredBuses)) {
                continue;
            }
            foreach (array_keys($configuredBuses) as $name) {
                if (is_string($name) && '' !== $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }
}
