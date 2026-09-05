<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CaptureBusChainPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.bus') as $id => $tags) {
            $references = $container->getDefinition($id)->getArgument(0)->getValues();
            $container->setParameter('reproduction.'.$id, array_map(static fn ($reference) => (string) $reference, $references));
        }
    }
}
