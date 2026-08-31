<?php

declare(strict_types=1);

namespace App;

use App\Controller\TenantContextController;
use App\Doctrine\ConsumerConnectionParametersProvider;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
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

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $strategy = $_SERVER['DATABASE_STRATEGY'] ?? 'shared_db';

        $container->loadFromExtension('framework', [
            'secret' => 'consumer-fixture-secret',
            'test' => true,
            'mailer' => ['dsn' => 'null://null'],
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'transports' => ['async' => 'in-memory://'],
                'routing' => ["App\Message\TenantMessage" => 'async'],
            ],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => $_SERVER['DATABASE_URL'] ?? 'sqlite:///%kernel.cache_dir%/consumer.db'],
            'orm' => [
                'mappings' => [
                    'App' => [
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/src/Entity',
                        'prefix' => "App\Entity",
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '%kernel.project_dir%/migrations'],
        ]);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => "App\Entity\Tenant",
            'database' => [
                'strategy' => $strategy,
                'enable_filter' => true,
                'rls' => ['enabled' => false],
            ],
            'listeners' => [
                'request_listener' => false,
                'doctrine_filter_listener' => false,
            ],
            'decorators' => [
                'cache' => ['enabled' => false],
                'logger' => ['enabled' => false],
            ],
            'fixtures' => ['enabled' => false],
            'mailer' => [
                'enabled' => true,
                'add_tenant_id_header' => false,
                'add_tenant_name_header' => false,
            ],
            'storage' => ['enabled' => false],
            'messenger' => ['enabled' => true],
        ]);

        $container->register(TenantContextController::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        if ('multi_db' === $strategy) {
            $container->register(ConsumerConnectionParametersProvider::class)
                ->setArgument('$databasePath', '%kernel.cache_dir%/consumer.db');
            $container->setAlias(TenantConnectionParametersProviderInterface::class, ConsumerConnectionParametersProvider::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('consumer_tenant_context', '/_test/tenant-context')
            ->controller(TenantContextController::class);
    }
}
