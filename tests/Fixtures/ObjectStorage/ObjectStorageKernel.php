<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class ObjectStorageKernel extends Kernel
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
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mtb-object-storage/'.$this->environment;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setAlias(TenantRegistryInterface::class, InMemoryTenantRegistry::class)->setPublic(true);
            }
        });
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', ['secret' => 'synthetic-object-storage', 'test' => true,
            'messenger' => ['transports' => ['async' => ['dsn' => 'in-memory://', 'options' => ['serialize' => true]]],
                'routing' => [StorageMessage::class => 'async', GlobalStorageMessage::class => 'async']],
        ]);
        $container->loadFromExtension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'],
            'orm' => ['mappings' => ['Fixture' => ['type' => 'attribute', 'dir' => dirname(__DIR__).'/Entity', 'prefix' => 'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity']]],
        ]);
        $container->loadFromExtension('doctrine_migrations', ['migrations_paths' => []]);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => TestTenant::class, 'resolver' => 'header', 'database' => ['rls' => ['enabled' => false]],
            'listeners' => ['request_listener' => false, 'doctrine_filter_listener' => false],
            'decorators' => ['cache' => ['enabled' => false], 'logger' => ['enabled' => false]],
            'fixtures' => ['enabled' => false], 'mailer' => ['enabled' => false], 'storage' => ['enabled' => false],
            'messenger' => ['routing_strategy' => 'symfony_routing'],
            'object_storage' => ['enabled' => true, 'namespace_resolver' => 'object.namespaces',
                'providers' => ['shared' => ['active_location' => 'shared_v1']],
                'locations' => ['shared_v1' => ['backend' => InstrumentedBackend::class, 'binding' => InstrumentedBackend::class, 'allowed_tenants' => ['*']]],
            ],
        ]);
        $container->register(InMemoryTenantRegistry::class)->setPublic(true);
        $container->register(InstrumentedBackend::class)->setPublic(true);
        $container->register('object.namespaces', ConfiguredTenantStorageNamespaceResolver::class)->setArguments([['1' => str_repeat('a', 64), '2' => str_repeat('b', 64)]]);
        $container->register(StorageMessageHandler::class)->setAutowired(true)->setPublic(true)
            ->addTag('messenger.message_handler', ['handles' => StorageMessage::class])
            ->addTag('messenger.message_handler', ['handles' => GlobalStorageMessage::class]);
    }
}
