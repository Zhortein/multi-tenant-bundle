<?php

declare(strict_types=1);

use ObjectStorageConsumer\Kernel;
use ObjectStorageConsumer\Tenant;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageAuditInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;

require dirname(__DIR__).'/vendor/autoload.php';

$enabled = '1' === getenv('MTB_OBJECT_ENABLED');
$kernel = new Kernel('prod_'.bin2hex(random_bytes(6)), false);
try {
    $kernel->boot();
    if ($kernel->getContainer()->has(TenantObjectStorageInterface::class) !== $enabled) {
        throw new RuntimeException('The production container has an incorrect object storage state.');
    }
    if (!$enabled && interface_exists('League\\Flysystem\\FilesystemOperator')) {
        throw new RuntimeException('The minimal consumer must have no Flysystem installation.');
    }
    if ($enabled) {
        $storage = $kernel->getContainer()->get(TenantObjectStorageInterface::class);
        if ('1' === getenv('MTB_OBJECT_AUDIT_ENABLED')) {
            $audit = $kernel->getContainer()->get(TenantObjectStorageAuditInterface::class);
            if ($storage !== $audit) {
                throw new RuntimeException('Audit must extend the existing facade.');
            }
            $context = $kernel->getContainer()->get(TenantContextInterface::class);
            foreach (['A', 'B', 'A'] as $id) {
                $context->setTenant(new Tenant($id));
                if (['shared_v1'] !== array_column($audit->inventoryLocations()->locations, 'locationId')) {
                    throw new RuntimeException('Unexpected lazy tenant inventory.');
                }
            }
            $context->clear();
            $secret = getenv('MTB_OBJECT_AUDIT_KEY');
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kernel->getCacheDir())) as $file) {
                if ($file->isFile() && is_string($secret) && '' !== $secret && str_contains(file_get_contents($file->getPathname()), $secret)) {
                    throw new RuntimeException('A runtime audit key was embedded in the compiled cache.');
                }
            }
        }
    }
    echo 'Production consumer compiled: object storage '.($enabled ? 'enabled' : 'disabled without Flysystem').".\n";
} finally {
    $kernel->shutdown();
}
