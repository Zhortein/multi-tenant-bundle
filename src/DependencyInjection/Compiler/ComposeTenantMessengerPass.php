<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\AddDefaultStampsMiddleware;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Messenger\Middleware\FailedMessageProcessingMiddleware;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Middleware\TraceableMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;

/**
 * Composes the public MessageBus constructor iterable after MessengerPass.
 *
 * @internal guarded by the compiled-container Symfony compatibility matrix
 */
final class ComposeTenantMessengerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zhortein_multi_tenant.messenger.enabled')
            || true !== $container->getParameter('zhortein_multi_tenant.messenger.enabled')
            || !$container->has(TenantWorkerMiddleware::class)) {
            return;
        }

        $guards = [TenantWorkerMiddleware::class, TenantSendingMiddleware::class, TenantMessengerTransportResolver::class];
        // These Symfony middleware normalize envelope metadata before classification.
        // DispatchAfterCurrentBus deliberately remains INSIDE the receive boundary:
        // it resumes a saved downstream stack without entering the bus again.
        $preparation = [
            AddDefaultStampsMiddleware::class,
            AddBusNameStampMiddleware::class,
            RejectRedeliveredMessageMiddleware::class,
            DecodeFailedMessageMiddleware::class,
            FailedMessageProcessingMiddleware::class,
            TraceableMiddleware::class,
        ];

        foreach ($container->findTaggedServiceIds('messenger.bus') as $busId => $tags) {
            $bus = $container->findDefinition($busId);
            $arguments = $bus->getArguments();
            $middleware = $arguments[0] ?? null;
            if (MessageBus::class !== $bus->getClass() || !$middleware instanceof IteratorArgument) {
                throw new \LogicException(sprintf('Cannot safely compose tenant protection for Messenger bus "%s": expected MessageBus with its compiled middleware iterable.', $busId));
            }

            $before = [];
            $after = [];
            $existingGuards = [];
            foreach ($middleware->getValues() as $reference) {
                if (!$reference instanceof Reference) {
                    throw new \LogicException(sprintf('Cannot safely compose tenant protection for Messenger bus "%s": expected middleware service references.', $busId));
                }
                $class = $this->serviceClass($container, (string) $reference);
                if (in_array($class, $guards, true)) {
                    // Keep an explicitly configured service (including its arguments).
                    $existingGuards[$class] ??= $reference;
                } elseif (in_array($class, $preparation, true)) {
                    $before[] = $reference;
                } else {
                    $after[] = $reference;
                }
            }

            $composed = $before;
            foreach ($guards as $class) {
                $composed[] = $existingGuards[$class] ?? new Reference($class);
            }
            $bus->replaceArgument(0, new IteratorArgument([...$composed, ...$after]));
        }
    }

    private function serviceClass(ContainerBuilder $container, string $id): ?string
    {
        $definition = $container->findDefinition($id);
        while (null === $definition->getClass() && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        $class = $definition->getClass();

        $resolved = null === $class ? null : $container->getParameterBag()->resolveValue($class);
        if (null !== $resolved && !is_string($resolved)) {
            throw new \LogicException(sprintf('Messenger middleware "%s" must resolve to a class name.', $id));
        }

        return $resolved;
    }
}
