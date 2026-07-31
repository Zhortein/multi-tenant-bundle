<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$installedBundle = dirname(__DIR__).'/vendor/zhortein/multi-tenant-bundle';
if (!is_dir($installedBundle) || is_link($installedBundle)) {
    throw new RuntimeException('The bundle must be installed as an external, non-symlinked Composer dependency.');
}

$strategy = $_SERVER['DATABASE_STRATEGY'] ?? 'shared_db';
$kernel = new Kernel('test_'.$strategy, false);
$kernel->boot();
$container = $kernel->getContainer();

if ($container->getParameter('zhortein_multi_tenant.database.strategy') !== $strategy) {
    throw new RuntimeException(sprintf('The %s database strategy was not compiled.', $strategy));
}

if ("App\Entity\Tenant" !== $container->getParameter('zhortein_multi_tenant.tenant_entity')) {
    throw new RuntimeException('The consumer tenant entity was not compiled.');
}

if (false !== $container->getParameter('zhortein_multi_tenant.mailer.add_tenant_id_header')
    || false !== $container->getParameter('zhortein_multi_tenant.mailer.add_tenant_name_header')) {
    throw new RuntimeException('Tenant email metadata headers must remain disabled in the consumer fixture.');
}

$kernel->shutdown();
echo sprintf("Consumer container compiled for %s.\n", $strategy);
