<?php

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AddDoctrineFilterCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.orm.entity_manager.filters')) {
            $container->setParameter('doctrine.orm.entity_manager.filters', []);
        }

        $filters = $container->getParameter('doctrine.orm.entity_manager.filters');
        $filters['tenant_filter'] = [
            'class' => \Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter::class,
            'enabled' => false,
        ];

        $container->setParameter('doctrine.orm.entity_manager.filters', $filters);
    }
}