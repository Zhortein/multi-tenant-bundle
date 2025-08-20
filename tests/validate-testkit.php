<?php

declare(strict_types=1);

/**
 * Test Kit Validation Script.
 *
 * This script validates that the Test Kit is properly set up and ready to use.
 * It checks for required dependencies, PostgreSQL availability, and RLS configuration.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

$output = new ConsoleOutput();

$output->writeln('');
$output->writeln('<info>🧪 Multi-Tenant Bundle Test Kit Validation</info>');
$output->writeln('');

$checks = [];
$allPassed = true;

// Check 1: PHP Extensions
$output->writeln('<comment>Checking PHP Extensions...</comment>');
$requiredExtensions = ['pdo', 'pdo_pgsql', 'json', 'mbstring'];
foreach ($requiredExtensions as $extension) {
    $loaded = extension_loaded($extension);
    $checks[] = [
        'Check' => "PHP Extension: {$extension}",
        'Status' => $loaded ? '✅ OK' : '❌ MISSING',
        'Details' => $loaded ? 'Loaded' : 'Required for Test Kit',
    ];
    if (!$loaded) {
        $allPassed = false;
    }
}

// Check 2: Required Files
$output->writeln('<comment>Checking Test Kit Files...</comment>');
$requiredFiles = [
    'tests/Toolkit/WithTenantTrait.php' => 'Core tenant context trait',
    'tests/Toolkit/TestData.php' => 'Test data builder',
    'tests/Toolkit/TenantWebTestCase.php' => 'HTTP testing base class',
    'tests/Toolkit/TenantCliTestCase.php' => 'CLI testing base class',
    'tests/Toolkit/TenantMessengerTestCase.php' => 'Messenger testing base class',
    'tests/Integration/RlsIsolationTest.php' => 'RLS isolation tests',
    'tests/docker-compose.yml' => 'PostgreSQL test setup',
    'tests/sql/init.sql' => 'Database initialization',
];

foreach ($requiredFiles as $file => $description) {
    $exists = file_exists(__DIR__.'/../'.$file);
    $checks[] = [
        'Check' => "File: {$file}",
        'Status' => $exists ? '✅ OK' : '❌ MISSING',
        'Details' => $exists ? $description : 'Required file missing',
    ];
    if (!$exists) {
        $allPassed = false;
    }
}

// Check 3: Composer Dependencies
$output->writeln('<comment>Checking Composer Dependencies...</comment>');
$requiredPackages = [
    'phpunit/phpunit' => 'PHPUnit testing framework',
    'symfony/framework-bundle' => 'Symfony framework',
    'doctrine/orm' => 'Doctrine ORM',
    'symfony/messenger' => 'Symfony Messenger',
];

$composerLock = json_decode(file_get_contents(__DIR__.'/../composer.lock'), true);
$installedPackages = [];
foreach ($composerLock['packages'] as $package) {
    $installedPackages[$package['name']] = $package['version'];
}

foreach ($requiredPackages as $package => $description) {
    $installed = isset($installedPackages[$package]);
    $version = $installed ? $installedPackages[$package] : 'Not installed';
    $checks[] = [
        'Check' => "Package: {$package}",
        'Status' => $installed ? '✅ OK' : '❌ MISSING',
        'Details' => $installed ? "Version: {$version}" : $description,
    ];
    if (!$installed) {
        $allPassed = false;
    }
}

// Check 4: PostgreSQL Availability
$output->writeln('<comment>Checking PostgreSQL Availability...</comment>');
$postgresAvailable = false;
$postgresError = '';

try {
    $dsn = $_ENV['DATABASE_URL'] ?? 'postgresql://test_user:test_password@localhost:5432/multi_tenant_test';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query('SELECT version()');
    $version = $stmt->fetchColumn();

    if (str_contains($version, 'PostgreSQL')) {
        $postgresAvailable = true;
        $postgresVersion = preg_match('/PostgreSQL ([\d.]+)/', $version, $matches) ? $matches[1] : 'Unknown';
        $checks[] = [
            'Check' => 'PostgreSQL Connection',
            'Status' => '✅ OK',
            'Details' => "Version: {$postgresVersion}",
        ];
    }
} catch (Exception $e) {
    $postgresError = $e->getMessage();
    $checks[] = [
        'Check' => 'PostgreSQL Connection',
        'Status' => '⚠️  UNAVAILABLE',
        'Details' => 'Optional for basic tests, required for RLS tests',
    ];
}

// Check 5: RLS Configuration (if PostgreSQL is available)
if ($postgresAvailable) {
    $output->writeln('<comment>Checking Row-Level Security Configuration...</comment>');

    try {
        // Check if test tables exist
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('test_tenants', 'test_products')");
        $tableCount = $stmt->fetchColumn();

        $checks[] = [
            'Check' => 'Test Tables',
            'Status' => $tableCount >= 2 ? '✅ OK' : '⚠️  MISSING',
            'Details' => $tableCount >= 2 ? 'test_tenants and test_products exist' : 'Run tests/sql/init.sql',
        ];

        if ($tableCount >= 2) {
            // Check RLS enabled
            $stmt = $pdo->query("SELECT relrowsecurity FROM pg_class WHERE relname = 'test_products'");
            $rlsEnabled = $stmt->fetchColumn();

            $checks[] = [
                'Check' => 'RLS Enabled on test_products',
                'Status' => $rlsEnabled ? '✅ OK' : '❌ DISABLED',
                'Details' => $rlsEnabled ? 'Row-Level Security enabled' : 'RLS not enabled',
            ];

            // Check RLS policy
            $stmt = $pdo->query("SELECT COUNT(*) FROM pg_policies WHERE tablename = 'test_products' AND policyname = 'tenant_isolation_policy'");
            $policyCount = $stmt->fetchColumn();

            $checks[] = [
                'Check' => 'RLS Policy',
                'Status' => $policyCount > 0 ? '✅ OK' : '❌ MISSING',
                'Details' => $policyCount > 0 ? 'tenant_isolation_policy exists' : 'Policy not found',
            ];

            if (!$rlsEnabled || 0 === $policyCount) {
                $allPassed = false;
            }
        } else {
            $allPassed = false;
        }
    } catch (Exception $e) {
        $checks[] = [
            'Check' => 'RLS Configuration',
            'Status' => '❌ ERROR',
            'Details' => $e->getMessage(),
        ];
        $allPassed = false;
    }
}

// Check 6: Test Kit Classes
$output->writeln('<comment>Checking Test Kit Classes...</comment>');
$testKitClasses = [
    'Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\WithTenantTrait' => 'Core tenant trait',
    'Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\TestData' => 'Test data builder',
    'Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\TenantWebTestCase' => 'HTTP test base',
    'Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\TenantCliTestCase' => 'CLI test base',
    'Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\TenantMessengerTestCase' => 'Messenger test base',
];

foreach ($testKitClasses as $class => $description) {
    $exists = class_exists($class) || trait_exists($class);
    $checks[] = [
        'Check' => 'Class: '.basename(str_replace('\\', '/', $class)),
        'Status' => $exists ? '✅ OK' : '❌ MISSING',
        'Details' => $exists ? $description : 'Class not found',
    ];
    if (!$exists) {
        $allPassed = false;
    }
}

// Display results table
$output->writeln('');
$output->writeln('<info>Validation Results:</info>');
$output->writeln('');

$table = new Table($output);
$table->setHeaders(['Check', 'Status', 'Details']);
$table->setRows($checks);
$table->render();

$output->writeln('');

// Summary
if ($allPassed) {
    $output->writeln('<info>🎉 All checks passed! Test Kit is ready to use.</info>');
    $output->writeln('');
    $output->writeln('<comment>Quick Start:</comment>');
    $output->writeln('  make test-kit                    # Run all Test Kit tests');
    $output->writeln('  make test-rls                    # Run RLS isolation tests');
    $output->writeln('  make postgres-start              # Start PostgreSQL for testing');
    $output->writeln('  vendor/bin/phpunit tests/Integration  # Run integration tests');
} else {
    $output->writeln('<error>❌ Some checks failed. Please fix the issues above.</error>');
    $output->writeln('');
    $output->writeln('<comment>Common Solutions:</comment>');
    $output->writeln('  composer install                 # Install dependencies');
    $output->writeln('  cd tests && docker-compose up -d postgres  # Start PostgreSQL');
    $output->writeln('  docker-compose exec postgres psql -U test_user -d multi_tenant_test -f /docker-entrypoint-initdb.d/init.sql  # Setup RLS');
}

$output->writeln('');
$output->writeln('<comment>Documentation:</comment>');
$output->writeln('  tests/README.md                  # Test Kit documentation');
$output->writeln('  docs/examples/test-kit-usage.md  # Usage examples');
$output->writeln('  docs/testing.md                  # Testing guide');
$output->writeln('');

exit($allPassed ? 0 : 1);
