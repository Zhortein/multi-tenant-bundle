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

$testContainer = $container->get('test.service_container');
$tenantContext = $testContainer->get(Zhortein\MultiTenantBundle\Context\TenantContextInterface::class);
$tenantContext->setTenant(new App\Entity\Tenant());
$testContainer->get(Symfony\Component\Messenger\MessageBusInterface::class)->dispatch(
    new stdClass(),
);
$transport = $testContainer->get('messenger.transport.async');
if (!$transport instanceof Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport) {
    throw new RuntimeException('The consumer fixture requires its in-memory async transport.');
}

$sent = $transport->getSent();
if (1 !== count($sent)) {
    throw new RuntimeException('The consumer message was not sent to the async transport.');
}

$tenantStamps = $sent[0]->all(Zhortein\MultiTenantBundle\Messenger\TenantStamp::class);
if (1 !== count($tenantStamps) || 'fixture' !== $tenantStamps[0]->getTenantId()) {
    throw new RuntimeException('The consumer message did not retain exactly one tenant stamp.');
}

$kernel->shutdown();
echo sprintf("Consumer container compiled for %s.\n", $strategy);
