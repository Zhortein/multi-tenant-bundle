#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__).'/vendor/autoload.php';

$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
if (!is_string($databaseUrl) || '' === $databaseUrl) {
    fwrite(STDERR, 'DATABASE_URL is required.'.PHP_EOL);
    exit(2);
}

$connection = DriverManager::getConnection((new DsnParser([
    'postgres' => 'pdo_pgsql',
    'postgresql' => 'pdo_pgsql',
]))->parse($databaseUrl));
foreach ([
    ['id' => 'migration-a', 'slug' => 'migration-a'],
    ['id' => 'migration-b', 'slug' => 'migration-b'],
] as $tenant) {
    $connection->insert('tenant', $tenant);
}
$connection->close();

fwrite(STDOUT, 'Seeded migration-a and migration-b.'.PHP_EOL);
