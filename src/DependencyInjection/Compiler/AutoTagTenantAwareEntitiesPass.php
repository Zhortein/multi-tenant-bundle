<?php

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

final class AutoTagTenantAwareEntitiesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zhortein_multi_tenant.entity_paths')) {
            return;
        }

        $paths = $container->getParameter('zhortein_multi_tenant.entity_paths');

        $tenantAwareEntities = [];

        foreach ($paths as $dir) {
            $finder = new Finder();
            $finder->files()->in($dir)->name('*.php');

            /** @var SplFileInfo $file */
            foreach ($finder as $file) {
                $className = $this->extractClassName($file->getRealPath());

                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                $attrs = $reflection->getAttributes(AsTenantAware::class);
                if (!empty($attrs)) {
                    $tenantAwareEntities[] = $className;
                }
            }
        }

        $container->setParameter('zhortein_multi_tenant.tenant_aware_entities', $tenantAwareEntities);
    }

    private function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if (!preg_match('/namespace\s+(.+?);/s', $contents, $m)) {
            return null;
        }
        $namespace = trim($m[1]);

        if (!preg_match('/class\s+(\w+)/', $contents, $m)) {
            return null;
        }

        return $namespace . '\\' . $m[1];
    }
}
