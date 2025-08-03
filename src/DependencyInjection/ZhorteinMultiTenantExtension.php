<?php

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Command\CreateTenantCommand;
use Zhortein\MultiTenantBundle\Command\ListTenantsCommand;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantConfigurationResolver;

final class ZhorteinMultiTenantExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set up file storage
        if ($config['storage']['default'] === 'local') {
            $definition = new Definition(LocalStorage::class);
            $definition->setArgument(0, $config['storage']['options']['local']['base_path'] ?? '%kernel.project_dir%/var/tenants');
            $definition->setArgument(1, $config['storage']['options']['local']['base_url'] ?? '');

            $container->setDefinition(TenantFileStorageInterface::class, $definition);

            $container->registerForAutoconfiguration(TenantFileStorageInterface::class)
                ->addTag('zhortein.tenant_file_storage');
        }

        // @todo Plus tard, tu pourras rendre ça configurable dans le YAML du bundle.
        $container->setParameter('zhortein_multi_tenant.entity_paths', [
            $container->getParameter('kernel.project_dir') . '/src/Entity',
        ]);

        if ($config['mailer']['enabled']) {
            $container->setParameter('zhortein_multi_tenant.mailer.enabled', true);
        }

        if ($config['messenger']['enabled']) {
            $container->setParameter('zhortein_multi_tenant.messenger.enabled', true);
        }

        // Enable asset uploader helper
        if (($config['helpers']['asset_uploader'] ?? true) === true) {
            $container->register(\Zhortein\MultiTenantBundle\Helper\TenantAssetUploader::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('zhortein.tenant_helper');
        }

        // Enable mailer helper
        if (($config['helpers']['mailer_helper'] ?? true) === true) {
            $container->register(\Zhortein\MultiTenantBundle\Helper\TenantMailerHelper::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('zhortein.tenant_helper');
        }

        // Enable messenger configurator
        if (($config['helpers']['messenger_configurator'] ?? true) === true) {
            $container->register(\Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('zhortein.tenant_helper');
        }

        // Inject tenant entity + resolver class via params
        $container->setParameter('zhortein_multi_tenant.entity_class', $config['tenant_entity']);
        $container->setParameter('zhortein_multi_tenant.resolver', $config['resolver']);

        $container->register(CreateTenantCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addArgument(new Reference('doctrine.orm.entity_manager'))
            ->addArgument('%zhortein_multi_tenant.entity_class%')
            ->addTag('console.command');

        $container->register(ListTenantsCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addArgument(new Reference('doctrine.orm.entity_manager'))
            ->addArgument('%zhortein_multi_tenant.entity_class%')
            ->addTag('console.command');

        $container->register(TenantSettingsManager::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('zhortein.tenant_helper');

        $container->register(TenantConfigurationResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('zhortein.tenant_helper');

        // Load YAML service definitions
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

    }
}