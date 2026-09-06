<?php

declare(strict_types=1);

use ObjectStorageConsumer\Kernel;
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
        $kernel->getContainer()->get(TenantObjectStorageInterface::class);
    }
    echo 'Production consumer compiled: object storage '.($enabled ? 'enabled' : 'disabled without Flysystem').".\n";
} finally {
    $kernel->shutdown();
}
