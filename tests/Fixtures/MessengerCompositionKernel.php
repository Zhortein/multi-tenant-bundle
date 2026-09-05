<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\DependencyInjection\Compiler\ComposeTenantMessengerPass;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionDefaultStampsMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionGlobalMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionProbe;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionSecondMiddleware;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionTenantMessage;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class MessengerCompositionKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new ZhorteinMultiTenantBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mtb-composition/'.$this->environment;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setAlias(TenantRegistryInterface::class, InMemoryTenantRegistry::class)->setPublic(true);
            }
        });
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $pass = new ComposeTenantMessengerPass();
                foreach ($container->findTaggedServiceIds('messenger.bus') as $id => $tags) {
                    $before = array_map(strval(...), $container->getDefinition($id)->getArgument(0)->getValues());
                    $pass->process($container);
                    $after = array_map(strval(...), $container->getDefinition($id)->getArgument(0)->getValues());
                    if ($before !== $after) {
                        throw new \LogicException('Repeated composition changed the chain.');
                    }
                    $container->setParameter('composition.'.$id, $after);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, -200);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $scenario = explode('_', $this->environment)[0];
        $middleware = ['validation', CompositionProbe::class, CompositionSecondMiddleware::class];
        $bus = ['middleware' => $middleware];
        if ('validation' === $scenario) {
            $bus = ['middleware' => ['validation']];
        } elseif ('explicit' === $scenario) {
            $bus['middleware'] = [TenantWorkerMiddleware::class, ...$middleware, TenantSendingMiddleware::class, TenantMessengerTransportResolver::class];
        } elseif ('nodefaults' === $scenario) {
            $bus = ['default_middleware' => false, 'middleware' => [
                'add_default_stamps_middleware', 'add_bus_name_stamp_middleware', 'dispatch_after_current_bus',
                ...(class_exists('Symfony\\Component\\Messenger\\Middleware\\DecodeFailedMessageMiddleware') ? ['decode_failed_message_middleware'] : []),
                'failed_message_processing_middleware', ...$middleware, 'send_message', 'handle_message',
            ]];
        }
        $messenger = [
            'transports' => ['async' => 'in-memory://', 'other' => 'in-memory://'],
            'routing' => [CompositionGlobalMessage::class => 'async'],
        ];
        if ('implicit' !== $scenario) {
            $messenger['default_bus'] = 'messenger.bus.default';
            $messenger['buses'] = [
                'messenger.bus.default' => $bus,
                'other.bus' => ['middleware' => [CompositionSecondMiddleware::class, 'validation']],
            ];
        }
        $container->loadFromExtension('framework', [
            'secret' => 'composition-test', 'test' => true,
            'validation' => ['enable_attributes' => true],
            'profiler' => ['enabled' => 'profiler' === $scenario, 'collect' => false],
            'messenger' => $messenger,
        ]);
        if ('split' === $scenario) {
            $loader->load(__DIR__.'/config/messenger_composition.yaml');
        }
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm' => ['mappings' => ['Fixture' => ['type' => 'attribute', 'dir' => __DIR__.'/Entity', 'prefix' => __NAMESPACE__.'\\Entity']]],
        ]);
        $container->loadFromExtension('doctrine_migrations', ['migrations_paths' => []]);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => TestTenant::class,
            'resolver' => 'header',
            'database' => ['rls' => ['enabled' => false]],
            'listeners' => ['request_listener' => false, 'doctrine_filter_listener' => false],
            'decorators' => ['cache' => ['enabled' => false], 'logger' => ['enabled' => false]],
            'fixtures' => ['enabled' => false], 'mailer' => ['enabled' => false], 'storage' => ['enabled' => false],
            'messenger' => ['enabled' => 'disabled' !== $scenario, 'routing_strategy' => 'tenanttransport' === $scenario ? 'tenant_transport' : 'symfony_routing', 'tenant_transport_map' => ['acme' => 'other']],
        ]);
        $container->register(InMemoryTenantRegistry::class)->setPublic(true);
        $container->register(CompositionProbe::class)->setAutowired(true)->setPublic(true);
        foreach ([CompositionTenantMessage::class, CompositionGlobalMessage::class, CompositionDefaultStampsMessage::class] as $message) {
            $container->getDefinition(CompositionProbe::class)->addTag('messenger.message_handler', ['handles' => $message]);
        }
        $container->register(CompositionSecondMiddleware::class)->setAutowired(true);
        $container->setAlias('composition.injected_bus', MessageBusInterface::class)->setPublic(true);
    }
}
