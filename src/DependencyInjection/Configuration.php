<?php

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('zhortein_multi_tenant');

        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('mailer')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->arrayNode('messenger')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->arrayNode('helpers')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('asset_uploader')->defaultTrue()->end()
            ->booleanNode('mailer_helper')->defaultTrue()->end()
            ->booleanNode('messenger_configurator')->defaultTrue()->end()
            ->end()
            ->end()
            ->scalarNode('tenant_entity')->defaultValue('App\\Entity\\Tenant')->end()
            ->enumNode('resolver')->values(['path', 'subdomain', 'custom'])->defaultValue('path')->end()
            ->scalarNode('default_tenant')->defaultNull()->end()
            ->arrayNode('storage')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('default')->defaultValue('local')->end()
            ->arrayNode('options')
            ->normalizeKeys(false)
            ->prototype('array')
            ->variablePrototype()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}