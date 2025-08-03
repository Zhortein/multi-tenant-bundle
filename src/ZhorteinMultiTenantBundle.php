<?php

namespace Zhortein\MultiTenantBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\AddDoctrineFilterCompilerPass;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\AutoTagTenantAwareEntitiesPass;

class ZhorteinMultiTenantBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddDoctrineFilterCompilerPass());
        $container->addCompilerPass(new AutoTagTenantAwareEntitiesPass());
    }
}