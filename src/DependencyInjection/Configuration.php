<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration definition for the multi-tenant bundle.
 *
 * This class defines the configuration tree structure and default values
 * for the bundle's configuration options.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('zhortein_multi_tenant');

        $treeBuilder->getRootNode()
            ->children()
                // Tenant entity configuration
                ->scalarNode('tenant_entity')
                    ->defaultValue('App\\Entity\\Tenant')
                    ->info('The fully qualified class name of your tenant entity')
                ->end()

                // Tenant resolver configuration
                ->enumNode('resolver')
                    ->values(['path', 'subdomain', 'header', 'custom'])
                    ->defaultValue('path')
                    ->info('The tenant resolution strategy to use')
                ->end()

                // Default tenant configuration
                ->scalarNode('default_tenant')
                    ->defaultNull()
                    ->info('Default tenant slug to use when no tenant is resolved')
                ->end()

                // Require tenant configuration
                ->booleanNode('require_tenant')
                    ->defaultFalse()
                    ->info('Whether to require a tenant for all requests')
                ->end()

                // Subdomain resolver configuration
                ->arrayNode('subdomain')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_domain')
                            ->defaultValue('localhost')
                            ->info('The base domain for subdomain resolution')
                        ->end()
                        ->arrayNode('excluded_subdomains')
                            ->scalarPrototype()->end()
                            ->defaultValue(['www', 'api', 'admin', 'mail', 'ftp'])
                            ->info('Subdomains to exclude from tenant resolution')
                        ->end()
                    ->end()
                ->end()

                // Header resolver configuration
                ->arrayNode('header')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')
                            ->defaultValue('X-Tenant-Slug')
                            ->info('HTTP header name to use for tenant resolution')
                        ->end()
                    ->end()
                ->end()

                // Database configuration
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('strategy')
                            ->values(['shared', 'separate'])
                            ->defaultValue('shared')
                            ->info('Database strategy: shared database with filtering or separate databases')
                        ->end()
                        ->booleanNode('enable_filter')
                            ->defaultTrue()
                            ->info('Whether to enable the Doctrine tenant filter')
                        ->end()
                    ->end()
                ->end()

                // Cache configuration
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultValue('cache.app')
                            ->info('Cache pool service to use for tenant settings')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(3600)
                            ->min(0)
                            ->info('Cache TTL in seconds for tenant settings')
                        ->end()
                    ->end()
                ->end()

                // Mailer configuration
                ->arrayNode('mailer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable tenant-aware mailer configuration')
                        ->end()
                    ->end()
                ->end()

                // Messenger configuration
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable tenant-aware messenger configuration')
                        ->end()
                    ->end()
                ->end()

                // Fixtures configuration
                ->arrayNode('fixtures')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable tenant-aware fixtures loading')
                        ->end()
                    ->end()
                ->end()

                // Events configuration
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('dispatch_database_switch')
                            ->defaultTrue()
                            ->info('Dispatch events when switching tenant databases')
                        ->end()
                    ->end()
                ->end()

                // Storage configuration
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable tenant-aware file storage')
                        ->end()
                        ->enumNode('type')
                            ->values(['local', 's3', 'custom'])
                            ->defaultValue('local')
                            ->info('Storage type to use')
                        ->end()
                        ->arrayNode('local')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('base_path')
                                    ->defaultValue('%kernel.project_dir%/var/storage')
                                    ->info('Base path for local storage')
                                ->end()
                                ->scalarNode('base_url')
                                    ->defaultValue('/uploads')
                                    ->info('Base URL for accessing stored files')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('s3')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('bucket')
                                    ->defaultNull()
                                    ->info('S3 bucket name')
                                ->end()
                                ->scalarNode('region')
                                    ->defaultValue('us-east-1')
                                    ->info('S3 region')
                                ->end()
                                ->scalarNode('base_url')
                                    ->defaultNull()
                                    ->info('Base URL for S3 files (CloudFront, etc.)')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Container scoping configuration
                ->arrayNode('container')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enable_tenant_scope')
                            ->defaultFalse()
                            ->info('Enable tenant-scoped services in the container')
                        ->end()
                    ->end()
                ->end()

                // Event listeners configuration
                ->arrayNode('listeners')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('request_listener')
                            ->defaultTrue()
                            ->info('Enable automatic tenant resolution from requests')
                        ->end()
                        ->booleanNode('doctrine_filter_listener')
                            ->defaultTrue()
                            ->info('Enable automatic Doctrine filter configuration')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
