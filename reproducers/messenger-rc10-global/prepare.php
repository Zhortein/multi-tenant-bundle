<?php

declare(strict_types=1);

$consumer = $argv[1] ?? '/consumer';
if (is_dir($consumer.'/vendor') || is_file($consumer.'/composer.lock')) {
    throw new RuntimeException('Export a fresh Consumer App from the exact RC10 archive first.');
}
$path = $consumer.'/composer.json';
$manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (isset($manifest['repositories'])) {
    throw new RuntimeException('The public RC10 reproducer forbids alternative Composer repositories.');
}
foreach (['require', 'require-dev'] as $group) {
    foreach (array_keys($manifest[$group]) as $package) {
        if (str_starts_with($package, 'symfony/')) {
            $manifest[$group][$package] = '~8.1.0';
        }
    }
}
$manifest['require']['zhortein/multi-tenant-bundle'] = '1.0.0-rc.10';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
copy(__DIR__.'/Rc10GlobalDispatchReproductionTest.php', $consumer.'/tests/Rc10GlobalDispatchReproductionTest.php');
