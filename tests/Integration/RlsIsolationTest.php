<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

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
 * Proves PostgreSQL RLS isolation through raw DBAL queries.
 *
 * The dedicated Compose target runs this class against PostgreSQL 16 with a
 * real pdo_pgsql connection. FORCE ROW LEVEL SECURITY ensures the table owner
 * cannot bypass the policy, and raw SQL keeps the proof independent from the
 * Doctrine tenant filter.
 */
#[Group('rls')]
final class RlsIsolationTest extends TestCase
{
    private const int TENANT_A_ID = 1;
    private const int TENANT_B_ID = 2;

    private Connection $adminConnection;
    private Connection $connection;
    private TenantContext $tenantContext;
    private TenantSessionConfigurator $sessionConfigurator;
    private TestTenant $tenantA;
    private TestTenant $tenantB;

    protected function setUp(): void
    {
        try {
            $this->adminConnection = DriverManager::getConnection($this->connectionParameters(
                $_ENV['TEST_DATABASE_USER'] ?? 'test_user',
                $_ENV['TEST_DATABASE_PASSWORD'] ?? 'test_password',
            ));
            $this->adminConnection->executeQuery('SELECT 1');
            $this->createSchemaAndSeedData();
            $this->connection = DriverManager::getConnection($this->connectionParameters(
                $_ENV['TEST_DATABASE_APP_USER'] ?? 'rls_test_app',
                $_ENV['TEST_DATABASE_APP_PASSWORD'] ?? 'rls_test_password',
            ));
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            if ('1' === ($_ENV['TEST_DATABASE_REQUIRED'] ?? null)) {
                throw new \RuntimeException('The required PostgreSQL RLS environment is unavailable.', 0, $exception);
            }

            $this->markTestSkipped('PostgreSQL is not available; run `make test-with-postgres` for mandatory RLS coverage.');
        }

        $this->tenantA = $this->createTenant(self::TENANT_A_ID, 'tenant-a', 'Tenant A');
        $this->tenantB = $this->createTenant(self::TENANT_B_ID, 'tenant-b', 'Tenant B');

        $registry = new InMemoryTenantRegistry();
        $registry->addTenant($this->tenantA);
        $registry->addTenant($this->tenantB);

        $this->tenantContext = new TenantContext();
        $this->sessionConfigurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $registry,
            true,
            'app.tenant_id',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->tenantContext->clear();
            $this->sessionConfigurator->setConfig();
            $this->connection->close();
        }
        if (isset($this->adminConnection) && $this->adminConnection->isConnected()) {
            $this->adminConnection->executeStatement('DROP TABLE IF EXISTS test_products');
            $this->adminConnection->executeStatement('DROP TABLE IF EXISTS test_tenants');
            $this->adminConnection->close();
        }

        parent::tearDown();
    }

    public function testPolicyIsEnabledAndForcedForTheTableOwner(): void
    {
        $state = $this->connection->fetchAssociative(<<<'SQL'
            SELECT relrowsecurity, relforcerowsecurity
            FROM pg_class
            WHERE oid = 'test_products'::regclass
            SQL);

        self::assertIsArray($state);
        self::assertTrue($state['relrowsecurity']);
        self::assertTrue($state['relforcerowsecurity']);

        $role = $this->connection->fetchAssociative(<<<'SQL'
    SELECT r.rolsuper, r.rolbypassrls, pg_get_userbyid(c.relowner) AS table_owner
    FROM pg_roles r
    CROSS JOIN pg_class c
    WHERE r.rolname = current_user
      AND c.oid = 'test_products'::regclass
    SQL);
        self::assertIsArray($role);
        self::assertFalse($role['rolsuper']);
        self::assertFalse($role['rolbypassrls']);
        self::assertNotSame('rls_test_app', $role['table_owner']);
        self::assertSame(1, (int) $this->connection->fetchOne(<<<'SQL'
            SELECT count(*)
            FROM pg_policies
            WHERE schemaname = 'public'
              AND tablename = 'test_products'
              AND policyname = 'tenant_isolation_policy'
            SQL));
    }

    public function testSameTenantReadsAreAllowedAndCrossTenantReadsAreDenied(): void
    {
        $this->configureTenant($this->tenantA);

        $rows = $this->connection->fetchAllAssociative('SELECT tenant_id, name FROM test_products ORDER BY id');

        self::assertCount(2, $rows, 'Tenant A must see both of its own rows.');
        self::assertSame([self::TENANT_A_ID], array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['tenant_id'],
            $rows,
        ))));
        self::assertNotContains('Tenant B secret', array_column($rows, 'name'));
    }

    public function testTenantSwitchReplacesTheVisibleDataset(): void
    {
        $this->configureTenant($this->tenantA);
        self::assertSame(['Tenant A product 1', 'Tenant A product 2'], $this->visibleProductNames());

        $this->configureTenant($this->tenantB);
        self::assertSame(['Tenant B secret'], $this->visibleProductNames());
        self::assertNotContains('Tenant A product 1', $this->visibleProductNames());
    }

    public function testNativeSqlRemainsIsolatedWithoutTheDoctrineTenantFilter(): void
    {
        $this->configureTenant($this->tenantB);

        $rows = $this->connection->executeQuery(
            'SELECT tenant_id, name FROM test_products WHERE tenant_id IN (?, ?) ORDER BY id',
            [self::TENANT_A_ID, self::TENANT_B_ID],
        )->fetchAllAssociative();

        self::assertSame([
            ['tenant_id' => self::TENANT_B_ID, 'name' => 'Tenant B secret'],
        ], array_map(
            static fn (array $row): array => ['tenant_id' => (int) $row['tenant_id'], 'name' => $row['name']],
            $rows,
        ));
    }

    public function testWritesAllowTheCurrentTenantAndRejectAnotherTenant(): void
    {
        $this->configureTenant($this->tenantA);

        $this->connection->insert('test_products', [
            'tenant_id' => self::TENANT_A_ID,
            'name' => 'Tenant A allowed insert',
            'price' => '15.00',
        ]);
        self::assertContains('Tenant A allowed insert', $this->visibleProductNames());

        try {
            $this->connection->insert('test_products', [
                'tenant_id' => self::TENANT_B_ID,
                'name' => 'Cross-tenant attack',
                'price' => '99.00',
            ]);
            self::fail('PostgreSQL RLS must reject a write targeting another tenant.');
        } catch (Exception $exception) {
            self::assertStringContainsString('row-level security policy', $exception->getMessage());
        }

        self::assertNotContains('Cross-tenant attack', $this->visibleProductNames());
    }

    public function testUpdatesAllowTheCurrentTenantAndHideAnotherTenant(): void
    {
        $this->configureTenant($this->tenantA);

        self::assertSame(1, $this->connection->update(
            'test_products',
            ['name' => 'Tenant A updated'],
            ['id' => 1],
        ));
        self::assertContains('Tenant A updated', $this->visibleProductNames());

        self::assertSame(0, $this->connection->update(
            'test_products',
            ['name' => 'Cross-tenant update'],
            ['id' => 3],
        ));
        self::assertSame(
            'Tenant B secret',
            $this->adminConnection->fetchOne('SELECT name FROM test_products WHERE id = 3'),
        );
    }

    public function testDeletesAllowTheCurrentTenantAndHideAnotherTenant(): void
    {
        $this->configureTenant($this->tenantA);

        self::assertSame(0, $this->connection->delete('test_products', ['id' => 3]));
        self::assertSame(1, $this->connection->delete('test_products', ['id' => 2]));
        self::assertSame(['Tenant A product 1'], $this->visibleProductNames());
        self::assertSame(
            'Tenant B secret',
            $this->adminConnection->fetchOne('SELECT name FROM test_products WHERE id = 3'),
        );
    }

    public function testFailureCleanupClearsSessionStateBeforeConnectionReuse(): void
    {
        $this->configureTenant($this->tenantA);
        $this->connection->beginTransaction();

        try {
            self::assertNotEmpty($this->visibleProductNames());
            throw new \RuntimeException('Simulated tenant operation failure.');
        } catch (\RuntimeException) {
            $this->connection->rollBack();
            $this->tenantContext->clear();
            $this->sessionConfigurator->setConfig();
        }

        self::assertSame('', $this->currentTenantSetting());
        self::assertSame([], $this->visibleProductNames());

        $this->configureTenant($this->tenantB);
        self::assertSame(['Tenant B secret'], $this->visibleProductNames());
    }

    public function testAReopenedConnectionHasNoInheritedTenantState(): void
    {
        $this->configureTenant($this->tenantA);
        self::assertNotEmpty($this->visibleProductNames());
        $this->connection->close();

        $this->connection = DriverManager::getConnection($this->connectionParameters(
            $_ENV['TEST_DATABASE_APP_USER'] ?? 'rls_test_app',
            $_ENV['TEST_DATABASE_APP_PASSWORD'] ?? 'rls_test_password',
        ));

        self::assertSame('', $this->currentTenantSetting());
        self::assertSame([], $this->visibleProductNames());
    }

    public function testMissingContextFailsClosedAndSessionStateIsCleared(): void
    {
        $this->configureTenant($this->tenantA);
        self::assertSame((string) self::TENANT_A_ID, $this->currentTenantSetting());
        self::assertNotEmpty($this->visibleProductNames());

        $this->tenantContext->clear();
        $this->sessionConfigurator->setConfig();

        self::assertSame('', $this->currentTenantSetting());
        self::assertSame([], $this->visibleProductNames(), 'No tenant context must expose no tenant-owned rows.');
    }

    private function createSchemaAndSeedData(): void
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
        $this->adminConnection->executeStatement('DROP TABLE IF EXISTS test_products');
        $this->adminConnection->executeStatement('DROP TABLE IF EXISTS test_tenants');
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE TABLE test_tenants (
                id INTEGER PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL
            )
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE TABLE test_products (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES test_tenants(id),
                name VARCHAR(255) NOT NULL,
                price NUMERIC(10, 2) NOT NULL
            )
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            INSERT INTO test_tenants (id, slug, name)
            VALUES (1, 'tenant-a', 'Tenant A'), (2, 'tenant-b', 'Tenant B')
            SQL);
        $this->adminConnection->executeStatement(<<<'SQL'
            INSERT INTO test_products (tenant_id, name, price)
            VALUES
                (1, 'Tenant A product 1', 10.00),
                (1, 'Tenant A product 2', 20.00),
                (2, 'Tenant B secret', 30.00)
            SQL);
        $this->adminConnection->executeStatement('ALTER TABLE test_products ENABLE ROW LEVEL SECURITY');
        $this->adminConnection->executeStatement('ALTER TABLE test_products FORCE ROW LEVEL SECURITY');
        $this->adminConnection->executeStatement(<<<'SQL'
            CREATE POLICY tenant_isolation_policy ON test_products
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::integer)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::integer)
            SQL);
        $this->adminConnection->executeStatement('GRANT USAGE ON SCHEMA public TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT SELECT ON test_tenants TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT SELECT, INSERT, UPDATE, DELETE ON test_products TO rls_test_app');
        $this->adminConnection->executeStatement('GRANT USAGE, SELECT ON SEQUENCE test_products_id_seq TO rls_test_app');
    }

    private function configureTenant(TestTenant $tenant): void
    {
        $this->tenantContext->setTenant($tenant);
        $this->sessionConfigurator->setConfig();
        self::assertSame((string) $tenant->getId(), $this->currentTenantSetting());
    }

    /** @return list<string> */
    private function visibleProductNames(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection->fetchAllAssociative('SELECT name FROM test_products ORDER BY id'),
        ));
    }

    private function currentTenantSetting(): string
    {
        return (string) $this->connection->fetchOne("SELECT current_setting('app.tenant_id', true)");
    }

    /** @return array{driver: string, host: string, port: int, dbname: string, user: string, password: string} */
    private function connectionParameters(string $user, string $password): array
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

    private function createTenant(int $id, string $slug, string $name): TestTenant
    {
        $tenant = new TestTenant();
        $property = new \ReflectionProperty($tenant, 'id');
        $property->setValue($tenant, $id);
        $tenant->setSlug($slug);
        $tenant->setName($name);
        $tenant->setActive(true);

        return $tenant;
    }
}
