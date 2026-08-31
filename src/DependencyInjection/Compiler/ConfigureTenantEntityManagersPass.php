<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerConfigurator;

final class ConfigureTenantEntityManagersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(TenantContextSynchronizerInterface::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (1 !== preg_match('/^doctrine\.orm\.[^.]+_entity_manager$/', $id)) {
                continue;
            }

            $configurator = $definition->getConfigurator();
            if (!is_array($configurator) || !$configurator[0] instanceof Reference) {
                throw new \LogicException(sprintf('EntityManager service "%s" has no composable Doctrine configurator.', $id));
            }

            $serviceId = 'zhortein_multi_tenant.entity_manager_configurator.'.substr(hash('sha256', $id), 0, 16);
            $container->setDefinition($serviceId, (new Definition(TenantEntityManagerConfigurator::class))
                ->setArguments([$configurator[0], new Reference(TenantContextSynchronizerInterface::class)]));
            $definition->setConfigurator([new Reference($serviceId), 'configure']);
        }
    }
}
