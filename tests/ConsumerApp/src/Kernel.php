<?php

declare(strict_types=1);

namespace App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
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
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/consumer.db'],
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
                'rls' => ['enabled' => false],
            ],
            'decorators' => [
                'cache' => ['enabled' => false],
                'logger' => ['enabled' => false],
            ],
            'fixtures' => ['enabled' => false],
            'mailer' => ['enabled' => true],
            'storage' => ['enabled' => false],
        ]);
    }
}
