<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

require dirname(__DIR__).'/vendor/autoload.php';

$optionalSymbols = [
    "Monolog\Processor\ProcessorInterface",
    "Psr\SimpleCache\CacheInterface",
    "Symfony\Component\Mailer\MailerInterface",
    "Symfony\Component\Scheduler\Schedule",
    "Twig\Environment",
];

foreach ($optionalSymbols as $symbol) {
    if (class_exists($symbol) || interface_exists($symbol)) {
        throw new RuntimeException(sprintf('Optional symbol %s must not be installed in the minimal test.', $symbol));
    }
}

if (!interface_exists(MessageBusInterface::class)) {
    throw new RuntimeException('Messenger must remain a runtime dependency for RC9 compatibility.');
}

foreach ([ZhorteinMultiTenantBundle::class, TenantContext::class, LocalStorage::class, TenantSessionConfigurator::class] as $requiredClass) {
    if (!class_exists($requiredClass)) {
        throw new RuntimeException(sprintf('Required class %s is unavailable.', $requiredClass));
    }
}

$container = new ContainerBuilder();
$container->setParameter('kernel.environment', 'test');
$container->setParameter('kernel.debug', false);
$container->setParameter('kernel.project_dir', sys_get_temp_dir().'/multi-tenant-minimal-app');
$container->register(TenantResolverInterface::class)
    ->setSynthetic(true)
    ->setPublic(true);

(new ZhorteinMultiTenantBundle())->build($container);
(new ZhorteinMultiTenantExtension())->load([[
    'resolver' => 'custom',
    'database' => ['rls' => ['enabled' => false]],
    'decorators' => [
        'cache' => ['enabled' => false],
        'logger' => ['enabled' => true],
    ],
    'fixtures' => ['enabled' => false],
    'mailer' => ['enabled' => true],
    'messenger' => ['enabled' => false],
    'storage' => ['enabled' => false],
]], $container);

if ($container->hasDefinition('zhortein_multi_tenant.mailer.tenant_aware')) {
    throw new RuntimeException('Mailer services were registered without Symfony Mailer.');
}

if ($container->hasDefinition('zhortein_multi_tenant.logger_processor')) {
    throw new RuntimeException('The Monolog processor was registered without Monolog.');
}

foreach ([TenantWorkerMiddleware::class, TenantSendingMiddleware::class, 'zhortein_multi_tenant.messenger.transport_resolver', 'zhortein_multi_tenant.messenger.transport_factory', 'zhortein_multi_tenant.messenger.configurator'] as $service) {
    if ($container->has($service)) {
        throw new RuntimeException(sprintf('Disabled Messenger integration registered service %s.', $service));
    }
}

$container->compile();

echo "Minimal production container compiled with Messenger installed and its integration explicitly disabled.\n";
