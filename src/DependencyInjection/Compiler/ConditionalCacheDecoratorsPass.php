<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;

/**
 * Conditionally registers PSR-16 cache decorators based on availability.
 *
 * This compiler pass checks if the PSR-16 interface is available and if the
 * target cache services exist before registering the simple cache decorators.
 */
final class ConditionalCacheDecoratorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Only proceed if cache decorators are enabled
        if (!$container->hasParameter('zhortein_multi_tenant.decorators.cache.enabled') ||
            !$container->getParameter('zhortein_multi_tenant.decorators.cache.enabled')) {
            return;
        }

        // Check if PSR-16 interface is available
        if (!interface_exists('Psr\SimpleCache\CacheInterface')) {
            return;
        }

        // Get the list of cache services to decorate
        if (!$container->hasParameter('zhortein_multi_tenant.decorators.cache.services')) {
            return;
        }

        $cacheServices = $container->getParameter('zhortein_multi_tenant.decorators.cache.services');
        if (!is_array($cacheServices)) {
            return;
        }

        foreach ($cacheServices as $serviceId) {
            if (!is_string($serviceId)) {
                continue;
            }
            $simpleServiceId = $serviceId.'.simple';

            // Only register the decorator if the simple cache service exists
            if ($container->hasDefinition($simpleServiceId)) {
                $container->register($serviceId.'.simple.tenant_aware', TenantAwareSimpleCacheDecorator::class)
                    ->setDecoratedService($simpleServiceId, null, 1) // Lower priority to avoid conflicts
                    ->setAutowired(true)
                    ->setArgument('$enabled', '%zhortein_multi_tenant.decorators.cache.enabled%');
            }
        }
    }
}