<?php

declare(strict_types=1);

// Run only in a disposable matrix checkout, before Composer resolution.
$symfony = $argv[1] ?? throw new RuntimeException('Symfony constraint is required.');
$mode = $argv[2] ?? throw new RuntimeException('Dependency mode is required.');
$file = getcwd().'/composer.json';
$composer = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
foreach (['require', 'require-dev'] as $section) {
    foreach ($composer[$section] as $name => $constraint) {
        if (str_starts_with($name, 'symfony/') && str_contains($constraint, '^7.4')) {
            $composer[$section][$name] = $symfony;
        }
    }
}
$composer['require-dev']['symfony/framework-bundle'] = $symfony;
$versions = match ($mode) {
    'low' => ['3.30.2', '3.30.1', '3.371.5'],
    'high' => ['3.36.0', '3.35.3', '3.394.9'],
    default => throw new RuntimeException('Unknown dependency mode.'),
};
foreach (['league/flysystem', 'league/flysystem-aws-s3-v3', 'aws/aws-sdk-php'] as $i => $package) {
    $composer['require-dev'][$package] = $versions[$i];
}
ksort($composer['require-dev']);
file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
