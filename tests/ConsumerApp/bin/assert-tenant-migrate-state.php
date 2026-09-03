#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__).'/vendor/autoload.php';

$expectedState = $argv[1] ?? null;
if (!in_array($expectedState, ['clean', 'migrated'], true)) {
    fwrite(STDERR, 'Usage: assert-tenant-migrate-state.php clean|migrated'.PHP_EOL);
    exit(2);
}

$databaseUrl = $_SERVER['MIGRATION_STATE_DATABASE_URL'] ?? $_ENV['MIGRATION_STATE_DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
if (!is_string($databaseUrl) || '' === $databaseUrl) {
    fwrite(STDERR, 'DATABASE_URL is required.'.PHP_EOL);
    exit(2);
}

$connection = DriverManager::getConnection((new DsnParser([
    'postgres' => 'pdo_pgsql',
    'postgresql' => 'pdo_pgsql',
]))->parse($databaseUrl));
$tables = [
    'tenant',
    'consumer_global_records',
    'consumer_tenant_records',
    'consumer_migration_probe',
    'doctrine_migration_versions',
];
$errors = [];

foreach ($tables as $table) {
    $exists = (bool) $connection->fetchOne('SELECT to_regclass(?) IS NOT NULL', [$table]);
    $shouldExist = 'migrated' === $expectedState;
    if ($exists !== $shouldExist) {
        $errors[] = sprintf('Table %s expected %s, got %s.', $table, $shouldExist ? 'present' : 'absent', $exists ? 'present' : 'absent');
    }
}

if ('migrated' === $expectedState && [] === $errors) {
    $versions = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
    $expectedVersions = [
        DoctrineMigrations\Version20260831000000::class,
        DoctrineMigrations\Version20260903010000::class,
    ];
    if ($versions !== $expectedVersions) {
        $errors[] = sprintf('Expected migration versions %s, got %s.', json_encode($expectedVersions), json_encode($versions));
    }

    $markers = $connection->fetchAllAssociative('SELECT sequence, marker FROM consumer_migration_probe ORDER BY sequence');
    if ([['sequence' => 1, 'marker' => 'tenant-migrate']] !== $markers) {
        $errors[] = sprintf('Unexpected migration probe rows: %s.', json_encode($markers));
    }
}

$connection->close();

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

printf('tenant:migrate database state is %s.%s', $expectedState, PHP_EOL);
