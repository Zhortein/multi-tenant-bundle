<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\MultiDatabaseConnectionParametersProvider;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\MultiDatabaseMessageHandler;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\MultiDatabaseMessengerProbe;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class MultiDatabaseMessengerKernel extends Kernel
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
        return $this->getProjectDir().'/var/cache/messenger_multi_db_'.(string) ($_SERVER['TEST_DATABASE_SERVER_VERSION'] ?? 'unknown');
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test-only-secret',
            'test' => true,
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'buses' => ['messenger.bus.default' => []],
                'transports' => ['multi_db_async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0]]],
                'routing' => [
                    'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Message\\MultiDatabaseTenantMessage' => 'multi_db_async',
                    'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Message\\MultiDatabaseGlobalMessage' => 'multi_db_async',
                ],
            ],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'url' => sprintf(
                    'postgresql://%s:%s@%s:%s/messenger_global_test',
                    rawurlencode((string) ($_SERVER['TEST_DATABASE_USER'] ?? 'test_user')),
                    rawurlencode((string) ($_SERVER['TEST_DATABASE_PASSWORD'] ?? '')),
                    (string) ($_SERVER['TEST_DATABASE_HOST'] ?? 'postgres'),
                    (string) ($_SERVER['TEST_DATABASE_PORT'] ?? '5432'),
                ),
                'server_version' => (string) ($_SERVER['TEST_DATABASE_SERVER_VERSION'] ?? '16'),
            ],
            'orm' => [
                'mappings' => [
                    'BundleTests' => [
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/tests/Fixtures/Entity',
                        'prefix' => 'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => 'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity\\TestTenant',
            'database' => ['strategy' => 'multi_db', 'enable_filter' => true, 'rls' => ['enabled' => false]],
            'listeners' => ['request_listener' => false, 'doctrine_filter_listener' => false],
            'messenger' => ['enabled' => true, 'default_transport' => 'multi_db_async'],
            'mailer' => ['enabled' => false],
            'fixtures' => ['enabled' => false],
            'storage' => ['enabled' => false],
            'decorators' => [
                'cache' => ['enabled' => false],
                'logger' => ['enabled' => false],
                'storage' => ['enabled' => false],
            ],
        ]);

        $container->register(NullLogger::class);
        $container->setAlias(LoggerInterface::class, NullLogger::class)->setPublic(true);
        $container->register(MultiDatabaseConnectionParametersProvider::class);
        $container->setAlias(TenantConnectionParametersProviderInterface::class, MultiDatabaseConnectionParametersProvider::class);
        $container->register(MultiDatabaseMessengerProbe::class)->setPublic(true);
        $container->register(MultiDatabaseMessageHandler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }
}
