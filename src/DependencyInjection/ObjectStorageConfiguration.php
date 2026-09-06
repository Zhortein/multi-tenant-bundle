<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationBindingInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantStorageNamespaceResolverInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantStorageProviderSelectorInterface;

/**
 * @internal
 *
 * @phpstan-type StorageConfig array{
 *   enabled: bool, default_provider: string, namespace_resolver: ?string, provider_selector: ?string,
 *   tenant_overrides: array<string|int, string>, providers: array<string, array{active_location: string}>,
 *   locations: array<string, array{backend: string, binding: string, allowed_tenants: list<string>, temporary_urls: bool}>,
 *   temporary_urls: array{enabled: bool, default_ttl: int, max_ttl: int}
 * }
 */
final class ObjectStorageConfiguration
{
    /** @return ArrayNodeDefinition<TreeBuilder<'array'>> */
    public static function node(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('object_storage'))->getRootNode();
        $node->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->scalarNode('default_provider')->cannotBeEmpty()->defaultValue('shared')->end()
            ->scalarNode('namespace_resolver')->defaultNull()->end()
            ->scalarNode('provider_selector')->defaultNull()->end()
            ->arrayNode('tenant_overrides')->normalizeKeys(false)->useAttributeAsKey('tenant_id')
                ->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('providers')->normalizeKeys(false)->useAttributeAsKey('id')
                ->arrayPrototype()->cannotBeOverwritten()->children()
                    ->scalarNode('active_location')->isRequired()->cannotBeEmpty()->end()
                ->end()->end()->end()
            ->arrayNode('locations')->normalizeKeys(false)->useAttributeAsKey('id')
                ->arrayPrototype()->cannotBeOverwritten()->children()
                    ->scalarNode('backend')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('binding')->isRequired()->cannotBeEmpty()->end()
                    ->arrayNode('allowed_tenants')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->booleanNode('temporary_urls')->defaultFalse()->end()
                ->end()->end()->end()
            ->arrayNode('temporary_urls')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->integerNode('default_ttl')->min(1)->defaultValue(300)->end()
                ->integerNode('max_ttl')->min(1)->max(86400)->defaultValue(900)->end()
            ->end()->end()
        ->end()->validate()->always(static function (mixed $config): array {
            /** @var StorageConfig $normalized */
            $normalized = $config;
            self::validate($normalized);

            return $normalized;
        })->end();

        return $node;
    }

    /** @param StorageConfig $config */
    private static function validate(array $config): void
    {
        if ($config['temporary_urls']['default_ttl'] > $config['temporary_urls']['max_ttl']) {
            throw new InvalidConfigurationException('object_storage: default_ttl must not exceed max_ttl.');
        }
        foreach (['namespace_resolver', 'provider_selector'] as $option) {
            if (null !== $config[$option]) {
                try {
                    Validation::nonEmptyString($config[$option]);
                } catch (\Throwable) {
                    throw new InvalidConfigurationException('object_storage: service IDs must be non-empty strings.');
                }
            }
        }
        try {
            Validation::identifier($config['default_provider']);
            foreach ($config['providers'] as $id => $provider) {
                Validation::identifier($id);
                Validation::identifier($provider['active_location']);
                if (!isset($config['locations'][$provider['active_location']])) {
                    throw new \LogicException('Unknown active location.');
                }
            }
            foreach ($config['locations'] as $id => $location) {
                Validation::identifier($id);
                foreach (['backend', 'binding'] as $option) {
                    Validation::nonEmptyString($location[$option]);
                }
                $allowed = $location['allowed_tenants'];
                if ([] === $allowed || count(array_unique($allowed)) !== count($allowed)
                    || (in_array('*', $allowed, true) && ['*'] !== $allowed)) {
                    throw new \LogicException('Invalid allowed_tenants list.');
                }
                foreach ($allowed as $tenantId) {
                    Validation::nonEmptyString($tenantId);
                    if ('*' !== $tenantId) {
                        Validation::tenantId($tenantId);
                    }
                }
            }
            foreach ($config['tenant_overrides'] as $tenant => $provider) {
                Validation::tenantId($tenant);
                if (!isset($config['providers'][$provider])) {
                    throw new \LogicException('Unknown tenant override provider.');
                }
            }
            if ($config['enabled'] && (null === $config['namespace_resolver'] || !isset($config['providers'][$config['default_provider']]))) {
                throw new \LogicException('Enabled object storage requires namespace_resolver and a registered default_provider.');
            }
        } catch (\LogicException $exception) {
            throw new InvalidConfigurationException('object_storage: '.$exception->getMessage());
        } catch (\Throwable) {
            throw new InvalidConfigurationException('object_storage: IDs must have valid types and formats; service IDs and allowed tenant IDs must be non-empty.');
        }
    }

    /** @param StorageConfig $config */
    public static function register(ContainerBuilder $container, array $config): void
    {
        if (!$config['enabled']) {
            return;
        }
        $requirements = [];
        $locations = [];
        foreach ($config['locations'] as $id => $location) {
            $service = 'zhortein_multi_tenant.object_storage.location.'.$id;
            $container->register($service, StorageLocation::class)->setArguments([
                $id, new Reference($location['backend']), new Reference($location['binding']),
                $location['allowed_tenants'], $location['temporary_urls'],
            ]);
            $locations[] = new Reference($service);
            $requirements[] = [$location['backend'], ObjectStorageBackendInterface::class];
            $requirements[] = [$location['binding'], StorageLocationBindingInterface::class];
            if ($location['temporary_urls']) {
                $requirements[] = [$location['backend'], TemporaryObjectUrlBackendInterface::class];
            }
        }
        $providers = array_map(static fn (array $provider): string => $provider['active_location'], $config['providers']);
        $container->register('zhortein_multi_tenant.object_storage.registry', ObjectStorageRegistry::class)->setArguments([$locations, $providers]);
        $container->setAlias(ObjectStorageRegistry::class, 'zhortein_multi_tenant.object_storage.registry');
        $selector = $config['provider_selector'] ?? 'zhortein_multi_tenant.object_storage.provider_selector';
        if (null === $config['provider_selector']) {
            $container->register($selector, ConfiguredTenantStorageProviderSelector::class)->setArguments([$config['default_provider'], $config['tenant_overrides']]);
        }
        $namespaceResolver = $config['namespace_resolver'];
        if (null === $namespaceResolver) {
            throw new \LogicException('object_storage requires a namespace resolver.');
        }
        $requirements[] = [$selector, TenantStorageProviderSelectorInterface::class];
        $requirements[] = [$namespaceResolver, TenantStorageNamespaceResolverInterface::class];
        if (TenantStorageProviderSelectorInterface::class !== $selector) {
            $container->setAlias(TenantStorageProviderSelectorInterface::class, $selector);
        }
        if (TenantStorageNamespaceResolverInterface::class !== $namespaceResolver) {
            $container->setAlias(TenantStorageNamespaceResolverInterface::class, $namespaceResolver);
        }
        $container->register('zhortein_multi_tenant.object_storage', TenantObjectStorage::class)->setArguments([
            new Reference(TenantContextInterface::class), new Reference($selector), new Reference($namespaceResolver),
            new Reference(ObjectStorageRegistry::class), $config['temporary_urls']['enabled'],
            $config['temporary_urls']['default_ttl'], $config['temporary_urls']['max_ttl'],
        ])->addTag('kernel.reset', ['method' => 'reset'])
            ->addTag('kernel.event_subscriber')
            ->addTag('zhortein_multi_tenant.lifecycle_resetter');
        $container->setAlias(TenantObjectStorageInterface::class, 'zhortein_multi_tenant.object_storage');
        $container->setParameter('zhortein_multi_tenant.object_storage.service_requirements', $requirements);
    }
}
