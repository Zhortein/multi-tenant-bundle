<?php

declare(strict_types=1);

namespace App;

use App\Controller\TenantContextController;
use App\Doctrine\ConsumerConnectionParametersProvider;
use App\EventListener\PostAuthenticationTenantLoader;
use App\Resolver\HeaderTenantResolver;
use App\Resolver\SecurityTenantResolver;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ('1' === ($_SERVER['SECURITY_ENABLED'] ?? '0')) {
            yield new SecurityBundle();
        }
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
        $cacheDecoratorEnabled = '0' !== ($_SERVER['CACHE_DECORATOR_ENABLED'] ?? '1');
        $automaticResolution = '1' === ($_SERVER['AUTO_RESOLUTION'] ?? '0');
        $securityEnabled = '1' === ($_SERVER['SECURITY_ENABLED'] ?? '0');
        $multipleDoctrineConnections = '1' === ($_SERVER['MULTIPLE_DOCTRINE_CONNECTIONS'] ?? '0');
        $customDefaultConnectionName = '1' === ($_SERVER['CUSTOM_DEFAULT_CONNECTION_NAME'] ?? '0');
        $defaultConnectionName = $customDefaultConnectionName ? 'primary' : 'default';
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? 'sqlite:///%kernel.cache_dir%/consumer.db';
        $appMapping = [
            'type' => 'attribute',
            'dir' => '%kernel.project_dir%/src/Entity',
            'prefix' => "App\Entity",
        ];
        $tenantFilterConfiguration = [
            'tenant_filter' => [
                'class' => TenantDoctrineFilter::class,
                'enabled' => true,
            ],
        ];
        $dbalConfiguration = $multipleDoctrineConnections ? [
            'default_connection' => $defaultConnectionName,
            'connections' => [
                $defaultConnectionName => ['url' => $databaseUrl],
                'reporting' => ['url' => $databaseUrl],
            ],
        ] : ['url' => $databaseUrl];
        $ormConfiguration = $multipleDoctrineConnections ? [
            'default_entity_manager' => $defaultConnectionName,
            'entity_managers' => [
                $defaultConnectionName => [
                    'connection' => $defaultConnectionName,
                    'mappings' => ['App' => $appMapping],
                    'filters' => $tenantFilterConfiguration,
                ],
                'reporting' => [
                    'connection' => 'reporting',
                    'mappings' => [],
                    'filters' => $tenantFilterConfiguration,
                ],
            ],
        ] : ['mappings' => ['App' => $appMapping]];

        $container->loadFromExtension('framework', [
            'secret' => 'consumer-fixture-secret',
            'test' => true,
            'cache' => [
                'app' => 'cache.adapter.array',
                'pools' => [
                    'cache.global' => ['adapter' => 'cache.adapter.array'],
                ],
            ],
            'mailer' => ['dsn' => 'null://null'],
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'transports' => ['async' => 'in-memory://'],
                'routing' => ["App\Message\TenantMessage" => 'async'],
            ],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => $dbalConfiguration,
            'orm' => $ormConfiguration,
        ]);
        $container->loadFromExtension('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '%kernel.project_dir%/migrations'],
        ]);
        if ($securityEnabled) {
            $container->loadFromExtension('security', [
                'password_hashers' => [
                    'Symfony\\Component\\Security\\Core\\User\\InMemoryUser' => 'plaintext',
                ],
                'providers' => [
                    'fixture_users' => [
                        'memory' => [
                            'users' => [
                                'tenant-a' => ['password' => 'fixture-password', 'roles' => ['ROLE_USER']],
                            ],
                        ],
                    ],
                ],
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'provider' => 'fixture_users',
                        'http_basic' => true,
                    ],
                ],
                'access_control' => [
                    ['path' => '^/_test', 'roles' => ['ROLE_USER']],
                ],
            ]);
        }
        $container->loadFromExtension('zhortein_multi_tenant', [
            'tenant_entity' => "App\Entity\Tenant",
            'database' => [
                'strategy' => $strategy,
                'enable_filter' => true,
                'rls' => ['enabled' => false],
            ],
            'listeners' => [
                'request_listener' => $automaticResolution,
                'doctrine_filter_listener' => false,
            ],
            'decorators' => [
                'cache' => ['enabled' => $cacheDecoratorEnabled],
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

        if ($securityEnabled) {
            $container->register(SecurityTenantResolver::class)
                ->setAutowired(true);
            $container->setAlias(TenantResolverInterface::class, SecurityTenantResolver::class);
            $container->register(PostAuthenticationTenantLoader::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        } else {
            $container->register(HeaderTenantResolver::class);
            $container->setAlias(TenantResolverInterface::class, HeaderTenantResolver::class);
        }

        if ('multi_db' === $strategy) {
            $container->register(ConsumerConnectionParametersProvider::class)
                ->setArgument('$databasePath', '%kernel.cache_dir%/consumer.db');
            $container->setAlias(TenantConnectionParametersProviderInterface::class, ConsumerConnectionParametersProvider::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('consumer_tenant_context', '/_test/tenant-context')
            ->controller([TenantContextController::class, 'context']);
        $routes->add('consumer_tenant_context_load', '/_test/tenant-context/load')
            ->controller([TenantContextController::class, 'load']);
    }
}
