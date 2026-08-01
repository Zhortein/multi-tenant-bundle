<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$publicFiles = [
    'src/Test/TenantContextScope.php',
    'src/Test/TenantKernelTestCase.php',
    'src/Test/TenantWebTestCase.php',
];

foreach ($publicFiles as $relativePath) {
    $path = $root.'/'.$relativePath;
    if (!is_file($path)) {
        $failures[] = sprintf('Missing public Test Kit file: %s', $relativePath);
        continue;
    }

    $source = file_get_contents($path);
    if (false === $source) {
        $failures[] = sprintf('Cannot read public Test Kit file: %s', $relativePath);
        continue;
    }

    foreach (["MultiTenantBundle\Tests", 'TestTenant', 'TestProduct', 'tests/Fixtures'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            $failures[] = sprintf('%s contains forbidden internal dependency %s.', $relativePath, $forbidden);
        }
    }
}

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$productionNamespace = $composer['autoload']['psr-4']['Zhortein\\MultiTenantBundle'.chr(92)] ?? null;
if ('src/' !== $productionNamespace) {
    $failures[] = 'The public Test Kit is not covered by the normal production PSR-4 mapping.';
}

$consumerFiles = [
    'tests/ConsumerApp/phpunit.xml.dist',
    'tests/ConsumerApp/tests/bootstrap.php',
    'tests/ConsumerApp/tests/PublicTestKitTest.php',
    'tests/ConsumerApp/tests/PublicTenantWebTest.php',
];

foreach ($consumerFiles as $relativePath) {
    if (!is_file($root.'/'.$relativePath)) {
        $failures[] = sprintf('Missing external consumer validation file: %s', $relativePath);
    }
}

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf("ERROR: %s\n", $failure));
    }

    exit(1);
}

fwrite(STDOUT, "Public Test Kit structure and consumer boundary validated.\n");
