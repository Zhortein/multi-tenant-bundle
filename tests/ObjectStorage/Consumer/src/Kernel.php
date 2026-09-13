<?php

declare(strict_types=1);

namespace ObjectStorageConsumer;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\SigningFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageAuditInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class Kernel extends BaseKernel
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
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mtb-object-consumer/'.$this->environment;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setAlias(TenantRegistryInterface::class, InMemoryTenantRegistry::class)->setPublic(true);
                foreach ([TenantObjectStorageInterface::class, TenantObjectStorageAuditInterface::class, TenantContextInterface::class, \Symfony\Component\Messenger\MessageBusInterface::class] as $alias) {
                    if ($container->hasAlias($alias)) {
                        $container->getAlias($alias)->setPublic(true);
                    }
                }
                if ($container->hasDefinition('messenger.transport.async')) {
                    $container->getDefinition('messenger.transport.async')->setPublic(true);
                }
            }
        });
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $enabled = '1' === getenv('MTB_OBJECT_ENABLED');
        $audit = '1' === getenv('MTB_OBJECT_AUDIT_ENABLED');
        $container->loadFromExtension('framework', [
            'secret' => 'disposable-object-storage-consumer',
            'messenger' => ['transports' => ['async' => ['dsn' => 'in-memory://', 'options' => ['serialize' => true]]],
                'routing' => [StorageMessage::class => 'async']],
        ]);
        $container->loadFromExtension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'],
            'orm' => ['mappings' => ['Consumer' => ['type' => 'attribute', 'dir' => __DIR__, 'prefix' => __NAMESPACE__]]],
        ]);
        $container->loadFromExtension('doctrine_migrations', ['migrations_paths' => []]);
        $storage = ['enabled' => $enabled];
        if ($enabled) {
            $container->register('object.config', S3LocationConfiguration::class)->setArguments([
                '%env(MTB_OBJECT_ENDPOINT)%', '%env(MTB_OBJECT_BUCKET)%', 'consumer', true, 'us-east-1', '%env(MTB_OBJECT_SIGNING_ENDPOINT)%',
            ]);
            $container->register('object.backend', $audit ? AuditableFlysystemBackend::class : SigningFlysystemBackend::class)
                ->setFactory([S3CompatibleStorageFactory::class, 'create'])
                ->setArguments([new Reference('object.config'), '%env(MTB_OBJECT_ACCESS_KEY)%', '%env(MTB_OBJECT_SECRET_KEY)%', true, '%env(MTB_OBJECT_CA)%', $audit]);
            $container->register('object.namespaces', ConfiguredTenantStorageNamespaceResolver::class)
                ->setArguments([['A' => str_repeat('a', 64), 'B' => str_repeat('b', 64)]]);
            $storage += ['namespace_resolver' => 'object.namespaces',
                'providers' => ['shared' => ['active_location' => 'shared_v1']],
                'locations' => ['shared_v1' => ['backend' => 'object.backend', 'binding' => 'object.backend', 'allowed_tenants' => ['*'], 'temporary_urls' => true, 'audit_listing' => $audit, 'identity_observation' => $audit]],
                'temporary_urls' => ['enabled' => true],
            ];
            if ($audit) {
                $storage['audit'] = ['enabled' => true, 'codec' => 'object.audit_codec'];
                $container->register('object.audit_codec', ObjectStorageAuditCodec::class)->setArguments(['current', ['current' => '%env(MTB_OBJECT_AUDIT_KEY)%']]);
            }
            $container->register(Handler::class)->setAutowired(true)->setPublic(true)
                ->addTag('messenger.message_handler', ['handles' => StorageMessage::class]);
        }
        $container->register(InMemoryTenantRegistry::class)->setPublic(true);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => Tenant::class, 'resolver' => 'header', 'database' => ['rls' => ['enabled' => false]],
            'listeners' => ['request_listener' => false, 'doctrine_filter_listener' => false],
            'decorators' => ['cache' => ['enabled' => false], 'logger' => ['enabled' => false]],
            'fixtures' => ['enabled' => false], 'mailer' => ['enabled' => false], 'storage' => ['enabled' => false],
            'messenger' => ['routing_strategy' => 'symfony_routing'], 'object_storage' => $storage,
        ]);
    }
}
