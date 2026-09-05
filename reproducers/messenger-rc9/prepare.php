<?php

declare(strict_types=1);

// Run inside the Consumer App Docker image, against a fresh RC9 fixture export.
$consumer = $argv[1] ?? '/consumer';
$symfony = $argv[2] ?? '8.1';
$graph = $argv[3] ?? 'aligned';
if (!in_array($symfony, ['7.4', '8.0', '8.1'], true)
    || !in_array($graph, ['aligned', 'exact'], true)
    || ('exact' === $graph && '8.1' !== $symfony)) {
    throw new InvalidArgumentException('Usage: prepare.php <fresh-consumer> <7.4|8.0|8.1> [aligned|exact]');
}
if (is_dir($consumer.'/vendor') || is_file($consumer.'/composer.lock')) {
    throw new RuntimeException('The consumer must be a fresh fixture export without vendor or a lock file.');
}

$manifest = json_decode(file_get_contents($consumer.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (isset($manifest['repositories'])) {
    throw new RuntimeException('Alternative Composer repositories are forbidden in the public reproducer.');
}
foreach (['require', 'require-dev'] as $group) {
    foreach (array_keys($manifest[$group]) as $package) {
        if (str_starts_with($package, 'symfony/')) {
            $manifest[$group][$package] = '~'.$symfony.'.0';
        }
    }
}
$manifest['require']['zhortein/multi-tenant-bundle'] = '1.0.0-rc.9';
$manifest['require']['symfony/validator'] = '~'.$symfony.'.0';
$manifest['require']['symfony/yaml'] = '~'.$symfony.'.0';
if ('exact' === $graph) {
    $manifest['require'] = array_replace($manifest['require'], [
        'symfony/cache' => '8.1.5',
        'symfony/framework-bundle' => '8.1.5',
        'symfony/messenger' => '8.1.5',
        'symfony/doctrine-messenger' => '8.1.4',
        'symfony/scheduler' => '8.1.5',
        'doctrine/orm' => '3.6.8',
        'doctrine/dbal' => '4.4.4',
        'doctrine/doctrine-bundle' => '3.3.1',
        'doctrine/doctrine-migrations-bundle' => '4.0.1',
        'doctrine/migrations' => '3.9.7',
    ]);
    $manifest['require-dev']['symfony/security-bundle'] = '8.1.6';
}
foreach (['require', 'require-dev'] as $group) {
    ksort($manifest[$group]);
}
file_put_contents($consumer.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

$kernelPath = $consumer.'/src/Kernel.php';
$kernel = file_get_contents($kernelPath);
$replacements = [
    "'mailer' => ['dsn' => 'null://null']," => "'mailer' => ['dsn' => 'null://null'],\n            'validation' => ['enable_attributes' => true],",
    "\$container->loadFromExtension('doctrine', [" => "\$loader->load(\$this->getProjectDir().'/config/packages/reproduction.yaml');\n        \$container->loadFromExtension('doctrine', [",
    '$container->register(RoutingProbe::class)->setPublic(true);' => <<<'PHP'
$container->register(RoutingProbe::class)->setPublic(true);
        $container->register(\App\Messenger\ReproductionProbe::class)->setAutowired(true)->setPublic(true)->addTag('messenger.message_handler');
        $container->addCompilerPass(new \App\Messenger\CaptureBusChainPass(), \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
PHP,
];
foreach ($replacements as $before => $after) {
    if (1 !== substr_count($kernel, $before)) {
        throw new RuntimeException('The fixture must be exported from the exact RC9 commit.');
    }
    $kernel = str_replace($before, $after, $kernel);
}
file_put_contents($kernelPath, $kernel);
mkdir($consumer.'/config/packages', 0777, true);
foreach ([
    'CaptureBusChainPass.php' => '/src/Messenger/CaptureBusChainPass.php',
    'ReproductionProbe.php' => '/src/Messenger/ReproductionProbe.php',
    'Rc9CompositionReproductionTest.php' => '/tests/Rc9CompositionReproductionTest.php',
    'reproduction.yaml' => '/config/packages/reproduction.yaml',
] as $source => $target) {
    if (!copy(__DIR__.'/fixtures/'.$source, $consumer.$target)) {
        throw new RuntimeException('Cannot copy reproduction fixture '.$source);
    }
}
echo 'Prepared a fresh public RC9 consumer; no alternative Composer repository was added.'.PHP_EOL;
