<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ValidateObjectStoragePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zhortein_multi_tenant.object_storage.service_requirements')) {
            return;
        }
        /** @var list<array{string, class-string}> $requirements */
        $requirements = $container->getParameter('zhortein_multi_tenant.object_storage.service_requirements');
        foreach ($requirements as [$id, $interface]) {
            if (!$container->has($id)) {
                throw new \LogicException(sprintf('object_storage: service "%s" must be registered and implement %s.', $id, $interface));
            }
            $class = $container->findDefinition($id)->getClass();
            $class = $container->getParameterBag()->resolveValue($class);
            if (!is_string($class) || !is_a($class, $interface, true)) {
                throw new \LogicException(sprintf('object_storage: service "%s" must declare a class implementing %s.', $id, $interface));
            }
        }
    }
}
