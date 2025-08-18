<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Functional\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

/**
 * Functional tests for PostgreSQL Row-Level Security (RLS) integration.
 *
 * These tests verify that RLS policies work correctly even when Doctrine
 * tenant filters are disabled, providing defense-in-depth protection.
 *
 * Note: These tests require a PostgreSQL database connection to be meaningful.
 * They will be skipped if PostgreSQL is not available.
 *
 * @group functional
 * @group database
 * @group rls
 */
final class RlsIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private TenantContext $tenantContext;
    private InMemoryTenantRegistry $tenantRegistry;
    private TenantSessionConfigurator $sessionConfigurator;

    protected function setUp(): void
    {
        // Try to create a PostgreSQL connection for testing
        // This will be skipped if no PostgreSQL is available
        try {
            $this->connection = $this->createPostgreSQLConnection();
            // Test the connection
            $this->connection->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('PostgreSQL connection not available for RLS testing: ' . $e->getMessage());
        }

        $this->tenantContext = new TenantContext();
        $this->tenantRegistry = new InMemoryTenantRegistry();
        $this->sessionConfigurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $this->tenantRegistry,
            true, // RLS enabled
            'app.tenant_id'
        );

        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            $this->cleanupTestData();
        }
        parent::tearDown();
    }

    public function testRlsPreventsAccessToOtherTenantsDataWhenFiltersDisabled(): void
    {
        // Create two tenants
        $tenant1 = $this->createTestTenant(1, 'tenant1', 'Tenant 1');
        $tenant2 = $this->createTestTenant(2, 'tenant2', 'Tenant 2');

        // Add tenants to registry
        $this->tenantRegistry->addTenant($tenant1);
        $this->tenantRegistry->addTenant($tenant2);

        // Insert test data directly via DBAL
        $this->connection->insert('test_tenants', [
            'id' => 1,
            'slug' => 'tenant1',
            'name' => 'Tenant 1',
            'active' => true,
        ]);
        $this->connection->insert('test_tenants', [
            'id' => 2,
            'slug' => 'tenant2', 
            'name' => 'Tenant 2',
            'active' => true,
        ]);

        $this->connection->insert('test_products', [
            'tenant_id' => 1,
            'name' => 'Product 1',
            'price' => '10.00',
        ]);
        $this->connection->insert('test_products', [
            'tenant_id' => 2,
            'name' => 'Product 2',
            'price' => '20.00',
        ]);

        // Set up RLS policies for the test_products table
        $this->setupRlsPolicies();

        // Set tenant context to tenant1
        $this->tenantContext->setTenant($tenant1);
        $this->configureSessionForCurrentTenant();

        // Disable Doctrine tenant filter to test RLS isolation
        $filters = $this->entityManager->getFilters();
        if ($filters->has('tenant_filter')) {
            $filters->disable('tenant_filter');
        }

        // Try to query all products directly via DBAL (bypassing Doctrine ORM filters)
        $products = $this->connection->fetchAllAssociative('SELECT * FROM test_products ORDER BY id');

        // With RLS enabled, should only see tenant1's product even with filters disabled
        $this->assertCount(1, $products, 'RLS should prevent access to other tenants\' data');
        $this->assertSame('Product 1', $products[0]['name']);
        $this->assertSame($tenant1->getId(), (int) $products[0]['tenant_id']);

        // Switch to tenant2
        $this->tenantContext->setTenant($tenant2);
        $this->configureSessionForCurrentTenant();

        // Query again - should now see only tenant2's product
        $products = $this->connection->fetchAllAssociative('SELECT * FROM test_products ORDER BY id');

        $this->assertCount(1, $products, 'RLS should show only current tenant\'s data');
        $this->assertSame('Product 2', $products[0]['name']);
        $this->assertSame($tenant2->getId(), (int) $products[0]['tenant_id']);
    }

    public function testRlsPreventsInsertWithWrongTenantId(): void
    {
        $tenant1 = $this->createTestTenant(1, 'tenant1', 'Tenant 1');
        $tenant2 = $this->createTestTenant(2, 'tenant2', 'Tenant 2');

        // Insert tenants
        $this->connection->insert('test_tenants', [
            'id' => 1,
            'slug' => 'tenant1',
            'name' => 'Tenant 1',
            'active' => true,
        ]);
        $this->connection->insert('test_tenants', [
            'id' => 2,
            'slug' => 'tenant2',
            'name' => 'Tenant 2',
            'active' => true,
        ]);

        $this->setupRlsPolicies();

        // Set tenant context to tenant1
        $this->tenantContext->setTenant($tenant1);
        $this->configureSessionForCurrentTenant();

        // Try to insert a product with tenant2's ID while tenant1 is active
        // This should fail due to RLS policy
        $this->expectException(Exception::class);

        $this->connection->insert('test_products', [
            'tenant_id' => $tenant2->getId(),
            'name' => 'Malicious Product',
            'price' => '99.99',
        ]);
    }

    public function testRlsAllowsInsertWithCorrectTenantId(): void
    {
        $tenant1 = $this->createTestTenant(1, 'tenant1', 'Tenant 1');

        // Insert tenant
        $this->connection->insert('test_tenants', [
            'id' => 1,
            'slug' => 'tenant1',
            'name' => 'Tenant 1',
            'active' => true,
        ]);

        $this->setupRlsPolicies();

        // Set tenant context to tenant1
        $this->tenantContext->setTenant($tenant1);
        $this->configureSessionForCurrentTenant();

        // Insert a product with the correct tenant ID - should succeed
        $this->connection->insert('test_products', [
            'tenant_id' => $tenant1->getId(),
            'name' => 'Valid Product',
            'price' => '15.99',
        ]);

        // Verify the product was inserted
        $products = $this->connection->fetchAllAssociative('SELECT * FROM test_products WHERE name = ?', ['Valid Product']);
        $this->assertCount(1, $products);
        $this->assertSame($tenant1->getId(), (int) $products[0]['tenant_id']);
    }

    public function testRlsWorksWithoutTenantContext(): void
    {
        $tenant1 = $this->createTestTenant(1, 'tenant1', 'Tenant 1');

        // Insert tenant and product
        $this->connection->insert('test_tenants', [
            'id' => 1,
            'slug' => 'tenant1',
            'name' => 'Tenant 1',
            'active' => true,
        ]);
        $this->connection->insert('test_products', [
            'tenant_id' => 1,
            'name' => 'Product 1',
            'price' => '10.00',
        ]);

        $this->setupRlsPolicies();

        // Clear tenant context (no session variable set)
        $this->tenantContext->clear();
        $this->connection->executeStatement('SELECT set_config(?, NULL, true)', ['app.tenant_id']);

        // Query should return no results due to RLS policy
        $products = $this->connection->fetchAllAssociative('SELECT * FROM test_products');
        $this->assertCount(0, $products, 'RLS should block access when no tenant context is set');
    }

    private function isPostgreSQL(): bool
    {
        return str_contains($this->connection->getDatabasePlatform()->getName(), 'postgresql');
    }

    private function setupTestData(): void
    {
        // Create test tables if they don't exist
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS test_tenants (
                id SERIAL PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS test_products (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES test_tenants (id)
            )
        ');
    }

    private function cleanupTestData(): void
    {
        try {
            // Drop RLS policies
            $this->connection->executeStatement('DROP POLICY IF EXISTS tenant_isolation_test_products ON test_products');
            $this->connection->executeStatement('ALTER TABLE test_products DISABLE ROW LEVEL SECURITY');

            // Clean up test data
            $this->connection->executeStatement('DELETE FROM test_products');
            $this->connection->executeStatement('DELETE FROM test_tenants');
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    private function setupRlsPolicies(): void
    {
        // Enable RLS on test_products table
        $this->connection->executeStatement('ALTER TABLE test_products ENABLE ROW LEVEL SECURITY');

        // Drop existing policy if it exists
        $this->connection->executeStatement('DROP POLICY IF EXISTS tenant_isolation_test_products ON test_products');

        // Create RLS policy
        $this->connection->executeStatement('
            CREATE POLICY tenant_isolation_test_products ON test_products
            USING (tenant_id::text = current_setting(\'app.tenant_id\', true))
        ');
    }



    private function configureSessionForCurrentTenant(): void
    {
        $tenant = $this->tenantContext->getTenant();
        if (null !== $tenant) {
            $this->connection->executeStatement(
                'SELECT set_config(?, ?, true)',
                ['app.tenant_id', (string) $tenant->getId()]
            );
        }
    }

    private function createPostgreSQLConnection(): Connection
    {
        // Try to connect to a test PostgreSQL database
        // This can be configured via environment variables
        $connectionParams = [
            'driver' => 'pdo_pgsql',
            'host' => $_ENV['TEST_DATABASE_HOST'] ?? 'localhost',
            'port' => $_ENV['TEST_DATABASE_PORT'] ?? 5432,
            'dbname' => $_ENV['TEST_DATABASE_NAME'] ?? 'test_multitenant',
            'user' => $_ENV['TEST_DATABASE_USER'] ?? 'postgres',
            'password' => $_ENV['TEST_DATABASE_PASSWORD'] ?? 'postgres',
        ];

        return DriverManager::getConnection($connectionParams);
    }

    private function createTestTenant(int $id, string $slug, string $name): TestTenant
    {
        $tenant = new TestTenant();
        // Use reflection to set the ID since it's normally auto-generated
        $reflection = new \ReflectionClass($tenant);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($tenant, $id);
        
        $tenant->setSlug($slug);
        $tenant->setName($name);
        $tenant->setActive(true);

        return $tenant;
    }
}