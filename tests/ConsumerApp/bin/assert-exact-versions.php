<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$expectedPhpVersionId = 80509;
$expectedPackages = [
    'symfony/cache' => '8.1.5',
    'symfony/framework-bundle' => '8.1.5',
    'symfony/messenger' => '8.1.5',
    'symfony/doctrine-messenger' => '8.1.4',
    'symfony/scheduler' => '8.1.5',
    'symfony/security-bundle' => '8.1.6',
    'doctrine/orm' => '3.6.8',
    'doctrine/dbal' => '4.4.4',
    'doctrine/doctrine-bundle' => '3.3.1',
    'doctrine/doctrine-migrations-bundle' => '4.0.1',
    'doctrine/migrations' => '3.9.7',
];

$errors = [];

if (PHP_VERSION_ID !== $expectedPhpVersionId) {
    $errors[] = sprintf('Expected PHP 8.5.9, got %s.', PHP_VERSION);
}

foreach ($expectedPackages as $package => $expectedVersion) {
    $installedVersion = InstalledVersions::getPrettyVersion($package);

    if (null === $installedVersion) {
        $errors[] = sprintf('Package %s is not installed.', $package);
        continue;
    }

    $normalizedVersion = ltrim($installedVersion, 'v');
    if ($normalizedVersion !== $expectedVersion) {
        $errors[] = sprintf('Expected %s %s, got %s.', $package, $expectedVersion, $installedVersion);
    }
}

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

printf('Exact Services Locaux dependency graph confirmed on PHP %s.%s', PHP_VERSION, PHP_EOL);
