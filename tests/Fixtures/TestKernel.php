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
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Command\TenantContextShowCommand;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Controller\TestProductsController;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class TestKernel extends Kernel
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
        $databaseUrl = (string) ($_SERVER['TEST_KERNEL_DATABASE_URL'] ?? $_ENV['TEST_KERNEL_DATABASE_URL'] ?? 'sqlite');

        return $this->getProjectDir().'/var/cache/'.$this->environment.'_'.substr(hash('sha256', $databaseUrl), 0, 12);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $databaseUrl = $_SERVER['TEST_KERNEL_DATABASE_URL'] ?? $_ENV['TEST_KERNEL_DATABASE_URL'] ?? 'sqlite:///%kernel.cache_dir%/bundle-functional.db';
        $container->loadFromExtension('framework', [
            'secret' => 'test-only-secret',
            'test' => true,
            'router' => ['utf8' => true],
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'buses' => [
                    'messenger.bus.default' => [],
                    'command.bus' => [],
                ],
                'transports' => ['async' => 'in-memory://'],
            ],
        ]);
        $dbal = ['url' => $databaseUrl];
        $serverVersion = $_SERVER['TEST_DATABASE_SERVER_VERSION'] ?? $_ENV['TEST_DATABASE_SERVER_VERSION'] ?? null;
        if (is_string($serverVersion) && '' !== $serverVersion) {
            $dbal['server_version'] = $serverVersion;
        }
        $container->loadFromExtension('doctrine', [
            'dbal' => $dbal,
            'orm' => [
                'mappings' => [
                    'BundleTests' => [
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/tests/Fixtures/Entity',
                        'prefix' => 'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity',
                    ],
                    'DoctrineIntegrationTests' => [
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/tests/Integration/Doctrine',
                        'prefix' => 'Zhortein\\MultiTenantBundle\\Tests\\Integration\\Doctrine',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('doctrine_migrations', [
            'migrations_paths' => ['BundleTestMigrations' => '%kernel.project_dir%/tests/Fixtures/Migrations'],
        ]);
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => 'Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity\\TestTenant',
            'resolver' => 'chain',
            'header' => ['name' => 'X-Tenant-ID'],
            'subdomain' => ['base_domain' => 'lvh.me'],
            'resolver_chain' => [
                'order' => ['subdomain', 'path', 'header', 'query', 'domain', 'hybrid', 'dns_txt'],
                'strict' => false,
                'header_allow_list' => ['X-Tenant-ID'],
            ],
            'database' => ['strategy' => 'shared_db', 'enable_filter' => true, 'rls' => ['enabled' => false]],
            'listeners' => ['request_listener' => true, 'doctrine_filter_listener' => false],
            'domain' => ['domain_mapping' => [
                'mairie-a.example.com' => 'mairie-a',
                'mairie-b.example.com' => 'mairie-b',
            ]],
            'messenger' => ['enabled' => true],
            'mailer' => ['enabled' => false],
            'fixtures' => ['enabled' => false],
            'storage' => ['enabled' => false],
            'decorators' => [
                'cache' => ['enabled' => true],
                'logger' => ['enabled' => false],
                'storage' => ['enabled' => false],
            ],
        ]);

        $container->register(NullLogger::class);
        $container->setAlias(LoggerInterface::class, NullLogger::class)->setPublic(true);
        $container->register(TenantContextShowCommand::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(TestProductsController::class)->setAutowired(true)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('bundle_test_products', '/test/products')->controller(TestProductsController::class);
        $routes->add('bundle_test_products_path', '/{tenant}/test/products')->controller(TestProductsController::class);
    }
}
