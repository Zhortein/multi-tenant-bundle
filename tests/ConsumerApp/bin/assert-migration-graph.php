#!/usr/bin/env php
<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$expected = [
    'doctrine/doctrine-migrations-bundle' => $argv[1] ?? null,
    'doctrine/migrations' => $argv[2] ?? null,
    'doctrine/dbal' => $argv[3] ?? null,
];
$errors = [];

foreach ($expected as $package => $expectedVersion) {
    if (!is_string($expectedVersion) || '' === $expectedVersion) {
        fwrite(STDERR, 'Usage: assert-migration-graph.php <bundle-version> <core-version> <dbal-version>'.PHP_EOL);
        exit(2);
    }

    $installedVersion = InstalledVersions::getPrettyVersion($package);
    if (ltrim($installedVersion ?? '', 'v') !== $expectedVersion) {
        $errors[] = sprintf('Expected %s %s, got %s.', $package, $expectedVersion, $installedVersion ?? 'not installed');
    }
}

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

printf(
    'Migration graph confirmed: Bundle %s, core %s, DBAL %s.%s',
    $expected['doctrine/doctrine-migrations-bundle'],
    $expected['doctrine/migrations'],
    $expected['doctrine/dbal'],
    PHP_EOL,
);
