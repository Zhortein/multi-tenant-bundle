#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__).'/vendor/autoload.php';

$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
$tenantUrls = [
    $_SERVER['TENANT_DATABASE_A_URL'] ?? $_ENV['TENANT_DATABASE_A_URL'] ?? null,
    $_SERVER['TENANT_DATABASE_B_URL'] ?? $_ENV['TENANT_DATABASE_B_URL'] ?? null,
];

if (!is_string($databaseUrl) || '' === $databaseUrl || [] === array_filter($tenantUrls, 'is_string')) {
    exit(0);
}

$parser = new DsnParser([
    'postgres' => 'pdo_pgsql',
    'postgresql' => 'pdo_pgsql',
]);
$connection = DriverManager::getConnection($parser->parse($databaseUrl));

foreach ($tenantUrls as $tenantUrl) {
    if (!is_string($tenantUrl) || '' === $tenantUrl) {
        fwrite(STDERR, 'Both TENANT_DATABASE_A_URL and TENANT_DATABASE_B_URL are required.'.PHP_EOL);
        exit(2);
    }

    $database = $parser->parse($tenantUrl)['dbname'] ?? null;
    if (!is_string($database) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $database)) {
        fwrite(STDERR, 'Tenant migration database names must use lowercase letters, digits, and underscores.'.PHP_EOL);
        exit(2);
    }

    if (false === $connection->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
        $connection->executeStatement(sprintf('CREATE DATABASE %s', $connection->quoteIdentifier($database)));
    }
}

$connection->close();
fwrite(STDOUT, 'Tenant migration databases are available.'.PHP_EOL);
