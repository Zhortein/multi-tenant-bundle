<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Functional\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

/**
 * Functional PostgreSQL RLS tests executed through a non-superuser role.
 *
 * @group functional
 * @group database
 * @group rls
 */
#[Group('rls')]
final class RlsIntegrationTest extends TestCase
{
    private Connection $adminConnection;
    private Connection $connection;
    private TenantContext $tenantContext;
    private TenantSessionConfigurator $configurator;
    private TestTenant $tenantA;
    private TestTenant $tenantB;

    protected function setUp(): void
    {
        try {
            $this->adminConnection = DriverManager::getConnection($this->parameters(
                $_ENV['TEST_DATABASE_USER'] ?? 'test_user',
                $_ENV['TEST_DATABASE_PASSWORD'] ?? 'test_password',
            ));
            $this->adminConnection->executeQuery('SELECT 1')->fetchOne();
            $this->setUpDatabase();
            $this->connection = DriverManager::getConnection($this->parameters(
                $_ENV['TEST_DATABASE_APP_USER'] ?? 'rls_test_app',
                $_ENV['TEST_DATABASE_APP_PASSWORD'] ?? 'rls_test_password',
            ));
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $exception) {
            if ('1' === ($_ENV['TEST_DATABASE_REQUIRED'] ?? null)) {
                throw new \RuntimeException('The required PostgreSQL RLS environment is unavailable.', 0, $exception);
            }

            $this->markTestSkipped('PostgreSQL is not available; run `make test-with-postgres` for mandatory RLS coverage.');
        }

        $this->tenantA = $this->tenant(1, 'tenant-a');
        $this->tenantB = $this->tenant(2, 'tenant-b');
        $registry = new InMemoryTenantRegistry();
        $registry->addTenant($this->tenantA);
        $registry->addTenant($this->tenantB);
        $this->tenantContext = new TenantContext();
        $this->configurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $registry,
            true,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->close();
        }
        if (isset($this->adminConnection) && $this->adminConnection->isConnected()) {
            $this->adminConnection->executeStatement('DROP TABLE IF EXISTS functional_rls_products');
            $this->adminConnection->executeStatement('DROP TABLE IF EXISTS functional_rls_tenants');
            $this->adminConnection->close();
        }

        parent::tearDown();
    }

    public function testRlsPreventsCrossTenantReadsThroughRawDbal(): void
    {
        $this->useTenant($this->tenantA);
        self::assertSame(['Tenant A product'], $this->names());

        $this->useTenant($this->tenantB);
        self::assertSame(['Tenant B product'], $this->names());
    }

    public function testRlsPreventsInsertWithAnotherTenantIdentifier(): void
    {
        $this->useTenant($this->tenantA);

        try {
            $this->connection->insert('functional_rls_products', [
                'tenant_id' => 2,
                'name' => 'Cross-tenant insert',
            ]);
            self::fail('RLS must reject an insert for another tenant.');
        } catch (Exception $exception) {
            self::assertStringContainsString('row-level security policy', $exception->getMessage());
        }
    }

    public function testRlsAllowsInsertForTheCurrentTenant(): void
    {
        $this->useTenant($this->tenantA);
        $this->connection->insert('functional_rls_products', [
            'tenant_id' => 1,
            'name' => 'Tenant A second product',
        ]);

        self::assertSame(['Tenant A product', 'Tenant A second product'], $this->names());
    }

    public function testRlsFailsClosedWithoutTenantContext(): void
    {
        $this->useTenant($this->tenantA);
        self::assertNotEmpty($this->names());

        $this->tenantContext->clear();
        $this->configurator->setConfig();

        self::assertSame([], $this->names());
    }

    private function setUpDatabase(): void
    {
        $this->adminConnection->executeStatement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'rls_test_app') THEN
                    CREATE ROLE rls_test_app LOGIN PASSWORD 'rls_test_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
                END IF;
            END
            $$
            SQL);
        $this->adminConnection->executeStatement('DROP TABLE IF EXISTS functional_rls_products');
        $this->adminConnection->executeStatement('DROP TABLE IF EXISTS functional_rls_tenants');
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE TABLE functional_rls_tenants (
                id INTEGER PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE
            )
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE TABLE functional_rls_products (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES functional_rls_tenants(id),
                name VARCHAR(255) NOT NULL
            )
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            INSERT INTO functional_rls_tenants (id, slug)
            VALUES (1, 'tenant-a'), (2, 'tenant-b')
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            INSERT INTO functional_rls_products (tenant_id, name)
            VALUES (1, 'Tenant A product'), (2, 'Tenant B product')
            SQL);
        $this->adminConnection->executeStatement('ALTER TABLE functional_rls_products ENABLE ROW LEVEL SECURITY');
        $this->adminConnection->executeStatement('ALTER TABLE functional_rls_products FORCE ROW LEVEL SECURITY');
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE POLICY functional_tenant_isolation ON functional_rls_products
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::integer)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::integer)
            SQL);
        $this->adminConnection->executeStatement('GRANT USAGE ON SCHEMA public TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT SELECT ON functional_rls_tenants TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT SELECT, INSERT, UPDATE, DELETE ON functional_rls_products TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT USAGE, SELECT ON SEQUENCE functional_rls_products_id_seq TO rls_test_app');
    }

    private function useTenant(TestTenant $tenant): void
    {
        $this->tenantContext->setTenant($tenant);
        $this->configurator->setConfig();
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection->fetchAllAssociative('SELECT name FROM functional_rls_products ORDER BY id'),
        ));
    }

    /** @return array{driver: string, host: string, port: int, dbname: string, user: string, password: string} */
    private function parameters(string $user, string $password): array
    {
        return [
            'driver' => 'pdo_pgsql',
            'host' => $_ENV['TEST_DATABASE_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['TEST_DATABASE_PORT'] ?? 5432),
            'dbname' => $_ENV['TEST_DATABASE_NAME'] ?? 'multi_tenant_test',
            'user' => $user,
            'password' => $password,
        ];
    }

    private function tenant(int $id, string $slug): TestTenant
    {
        $tenant = new TestTenant();
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, $id);
        $tenant->setSlug($slug);
        $tenant->setName(ucwords(str_replace('-', ' ', $slug)));
        $tenant->setActive(true);

        return $tenant;
    }
}
