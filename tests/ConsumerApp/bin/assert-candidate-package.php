#!/usr/bin/env php
<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$expectedCommit = $_SERVER['EXPECTED_CANDIDATE_COMMIT'] ?? $_ENV['EXPECTED_CANDIDATE_COMMIT'] ?? null;
if (!is_string($expectedCommit) || 1 !== preg_match('/^[0-9a-f]{40}$/', $expectedCommit)) {
    fwrite(STDERR, 'EXPECTED_CANDIDATE_COMMIT must contain a 40-character commit.'.PHP_EOL);
    exit(2);
}

$package = 'zhortein/multi-tenant-bundle';
$version = InstalledVersions::getPrettyVersion($package);
$reference = InstalledVersions::getReference($package);

if ('dev-candidate' !== $version || $expectedCommit !== $reference) {
    fwrite(STDERR, sprintf(
        'Expected %s dev-candidate at %s, got %s at %s.%s',
        $package,
        $expectedCommit,
        $version ?? 'no version',
        $reference ?? 'no reference',
        PHP_EOL,
    ));
    exit(1);
}

printf('Candidate package dev-candidate matches commit %s.%s', $expectedCommit, PHP_EOL);
