<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Checks symbols before the existing pass reflects on an enabled bridge class. */
final class ValidateFlysystemBridgePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zhortein_multi_tenant.object_storage.service_requirements')) {
            return;
        }
        /** @var list<array{string, class-string}> $requirements */
        $requirements = $container->getParameter('zhortein_multi_tenant.object_storage.service_requirements');
        foreach ($requirements as [$id]) {
            if (!$container->has($id)) {
                continue;
            }
            $definition = $container->findDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!is_string($class) || !str_starts_with($class, 'Zhortein\\MultiTenantBundle\\ObjectStorage\\Bridge\\Flysystem\\')) {
                continue;
            }
            if (!interface_exists('League\\Flysystem\\FilesystemOperator')) {
                throw new \LogicException('object_storage Flysystem bridge requires league/flysystem:^3.30.2. Run composer require league/flysystem:^3.30.2 or disable the bridge.');
            }
            $factory = $definition->getFactory();
            if (is_array($factory) && 'Zhortein\\MultiTenantBundle\\ObjectStorage\\Bridge\\Flysystem\\S3CompatibleStorageFactory' === $factory[0]
                && (!class_exists('League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter') || !class_exists('Aws\\S3\\S3Client'))) {
                throw new \LogicException('object_storage S3 bridge requires league/flysystem-aws-s3-v3:^3.30.1 and aws/aws-sdk-php:^3.371.5. Install them with Composer or disable the bridge.');
            }
        }
    }
}
