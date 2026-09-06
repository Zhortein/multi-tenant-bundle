<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (!interface_exists('League\\Flysystem\\FilesystemOperator') || class_exists('Aws\\S3\\S3Client')
    || class_exists('League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter')) {
    throw new RuntimeException('This test requires Flysystem without the S3 adapter or SDK.');
}
$class = 'Zhortein\\MultiTenantBundle\\ObjectStorage\\Bridge\\Flysystem\\SigningFlysystemBackend';
$container = new ContainerBuilder();
(new ZhorteinMultiTenantBundle())->build($container);
$container->register('object.s3', $class)->setPublic(true)
    ->setFactory(['Zhortein\\MultiTenantBundle\\ObjectStorage\\Bridge\\Flysystem\\S3CompatibleStorageFactory', 'create']);
$container->setParameter('zhortein_multi_tenant.object_storage.service_requirements', [
    ['object.s3', 'Zhortein\\MultiTenantBundle\\ObjectStorage\\ObjectStorageBackendInterface'],
]);
try {
    $container->compile();
    throw new RuntimeException('A missing S3 dependency must fail compilation.');
} catch (LogicException $e) {
    if (!str_contains($e->getMessage(), 'league/flysystem-aws-s3-v3:')) {
        throw $e;
    }
}
if (class_exists($class, false)) {
    throw new RuntimeException('Missing S3 dependency check loaded the bridge class.');
}
echo "Missing S3 adapter/SDK rejected before bridge loading.\n";
