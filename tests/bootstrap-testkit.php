<?php

declare(strict_types=1);

/*
 * Test Kit Bootstrap
 *
 * This bootstrap file is specifically designed for the Multi-Tenant Bundle Test Kit.
 * It sets up the environment for integration testing with PostgreSQL and RLS.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Load environment variables
if (file_exists(dirname(__DIR__).'/.env.test.local')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test.local');
} elseif (file_exists(dirname(__DIR__).'/.env.test')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test');
} elseif (file_exists(dirname(__DIR__).'/.env.local')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.local');
} elseif (file_exists(dirname(__DIR__).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Set default test environment variables
$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'test';
$_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? '1';

// Test Kit specific environment variables
$_ENV['TESTKIT_ENABLE_RLS'] = $_ENV['TESTKIT_ENABLE_RLS'] ?? '1';
$_ENV['TESTKIT_POSTGRES_REQUIRED'] = $_ENV['TESTKIT_POSTGRES_REQUIRED'] ?? '1';

// Default database URL for Test Kit (PostgreSQL required)
if (!isset($_ENV['DATABASE_URL'])) {
    $_ENV['DATABASE_URL'] = 'postgresql://test_user:test_password@localhost:5432/multi_tenant_test';
}

// Verify PostgreSQL is available if required
if ('1' === $_ENV['TESTKIT_POSTGRES_REQUIRED']) {
    $postgresAvailable = checkPostgreSqlAvailability();

    if (!$postgresAvailable) {
        echo "\n";
        echo "❌ PostgreSQL is required for Test Kit but not available.\n";
        echo "\n";
        echo "To start PostgreSQL for testing:\n";
        echo "  cd tests && docker-compose up -d postgres\n";
        echo "\n";
        echo "Or set TESTKIT_POSTGRES_REQUIRED=0 to skip PostgreSQL tests.\n";
        echo "\n";
        exit(1);
    }

    echo "✅ PostgreSQL is available for Test Kit\n";
}

// Verify RLS is enabled if required
if ('1' === $_ENV['TESTKIT_ENABLE_RLS'] && $postgresAvailable ?? false) {
    $rlsEnabled = checkRowLevelSecurityEnabled();

    if (!$rlsEnabled) {
        echo "\n";
        echo "⚠️  Row-Level Security (RLS) is not properly configured.\n";
        echo "\n";
        echo "To setup RLS for testing:\n";
        echo "  cd tests && docker-compose exec postgres psql -U test_user -d multi_tenant_test -f /docker-entrypoint-initdb.d/init.sql\n";
        echo "\n";
        echo "Or set TESTKIT_ENABLE_RLS=0 to skip RLS tests.\n";
        echo "\n";
    } else {
        echo "✅ Row-Level Security (RLS) is enabled\n";
    }
}

/**
 * Check if PostgreSQL is available and accessible.
 */
function checkPostgreSqlAvailability(): bool
{
    try {
        $dsn = $_ENV['DATABASE_URL'];
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Test connection with a simple query
        $stmt = $pdo->query('SELECT version()');
        $version = $stmt->fetchColumn();

        return str_contains($version, 'PostgreSQL');
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if Row-Level Security is properly configured.
 */
function checkRowLevelSecurityEnabled(): bool
{
    try {
        $dsn = $_ENV['DATABASE_URL'];
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if test_products table exists and has RLS enabled
        $stmt = $pdo->query("
            SELECT relrowsecurity 
            FROM pg_class 
            WHERE relname = 'test_products'
        ");

        $rlsEnabled = $stmt->fetchColumn();

        if (!$rlsEnabled) {
            return false;
        }

        // Check if RLS policy exists
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM pg_policies 
            WHERE tablename = 'test_products' 
            AND policyname = 'tenant_isolation_policy'
        ");

        $policyCount = $stmt->fetchColumn();

        return $policyCount > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Set up error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone for consistent test results
date_default_timezone_set('UTC');

echo "🧪 Test Kit Bootstrap Complete\n";
echo "   Environment: {$_ENV['APP_ENV']}\n";
echo '   Database: '.(str_contains($_ENV['DATABASE_URL'], 'postgresql') ? 'PostgreSQL' : 'Other')."\n";
echo '   RLS Enabled: '.('1' === $_ENV['TESTKIT_ENABLE_RLS'] ? 'Yes' : 'No')."\n";
echo "\n";
