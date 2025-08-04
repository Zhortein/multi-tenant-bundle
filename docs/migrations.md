# Tenant Migrations

The tenant migration system handles database schema changes for multi-tenant applications, supporting both shared database and multi-database strategies. It ensures that all tenants receive schema updates consistently and safely.

## Overview

The migration system provides:

- **Strategy-aware migrations**: Different behavior for shared-db vs multi-db
- **Tenant-specific execution**: Run migrations for specific tenants
- **Batch operations**: Execute migrations across all tenants
- **Rollback support**: Safely rollback migrations when needed
- **Dry-run capability**: Preview migration SQL without execution
- **Progress tracking**: Monitor migration progress across tenants

## Database Strategies

### Shared Database Strategy

In shared database mode, migrations run once on the shared database:

```bash
# Run migrations on shared database
php bin/console tenant:migrate

# All tenants share the same schema
# Tenant filtering is handled by Doctrine filters
```

### Multi-Database Strategy

In multi-database mode, migrations run on each tenant's database:

```bash
# Run migrations for all tenants
php bin/console tenant:migrate

# Run migrations for specific tenant
php bin/console tenant:migrate --tenant=acme

# Each tenant has its own database schema
```

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db' # or 'multi_db'
        enable_filter: true
        connection_prefix: 'tenant_'
```

### Doctrine Migrations Configuration

```yaml
# config/packages/doctrine_migrations.yaml
doctrine_migrations:
    migrations_paths:
        'DoctrineMigrations': '%kernel.project_dir%/migrations'
    organize_migrations: false
    storage:
        table_storage:
            table_name: 'doctrine_migration_versions'
            version_column_name: 'version'
            version_column_length: 1024
            executed_at_column_name: 'executed_at'
            execution_time_column_name: 'execution_time'
```

## Creating Migrations

### Standard Migration

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for creating products table
 */
final class Version20240101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create products table with tenant support';
    }

    public function up(Schema $schema): void
    {
        // Shared DB: Include tenant_id column
        $this->addSql('CREATE TABLE products (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description TEXT,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_products_tenant ON products (tenant_id)');
        $this->addSql('CREATE INDEX idx_products_tenant_active ON products (tenant_id, active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE products');
    }
}
```

### Strategy-Aware Migration

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration that adapts to database strategy
 */
final class Version20240101130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create orders table (strategy-aware)';
    }

    public function up(Schema $schema): void
    {
        $isSharedDb = $this->isSharedDatabaseStrategy();

        if ($isSharedDb) {
            // Shared database: include tenant_id
            $this->addSql('CREATE TABLE orders (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                order_number VARCHAR(255) NOT NULL,
                customer_email VARCHAR(255) NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'pending\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                UNIQUE KEY unique_order_per_tenant (tenant_id, order_number)
            )');

            $this->addSql('CREATE INDEX idx_orders_tenant ON orders (tenant_id)');
            $this->addSql('CREATE INDEX idx_orders_tenant_status ON orders (tenant_id, status)');
        } else {
            // Multi-database: no tenant_id needed
            $this->addSql('CREATE TABLE orders (
                id SERIAL PRIMARY KEY,
                order_number VARCHAR(255) NOT NULL UNIQUE,
                customer_email VARCHAR(255) NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'pending\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');

            $this->addSql('CREATE INDEX idx_orders_status ON orders (status)');
            $this->addSql('CREATE INDEX idx_orders_created ON orders (created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE orders');
    }

    private function isSharedDatabaseStrategy(): bool
    {
        // Check if we're in shared database mode
        // This could be determined by checking configuration or environment
        return $_ENV['TENANT_DB_STRATEGY'] === 'shared_db';
    }
}
```

### Data Migration

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data migration example
 */
final class Version20240101140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate product categories to new format';
    }

    public function up(Schema $schema): void
    {
        // Add new category_id column
        $this->addSql('ALTER TABLE products ADD COLUMN category_id INTEGER');

        // Create categories table
        $isSharedDb = $this->isSharedDatabaseStrategy();
        
        if ($isSharedDb) {
            $this->addSql('CREATE TABLE categories (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                UNIQUE KEY unique_category_per_tenant (tenant_id, slug)
            )');
        } else {
            $this->addSql('CREATE TABLE categories (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
        }

        // Migrate existing category data
        $this->migrateExistingCategories($isSharedDb);

        // Add foreign key constraint
        $this->addSql('ALTER TABLE products ADD CONSTRAINT fk_products_category 
            FOREIGN KEY (category_id) REFERENCES categories (id)');

        // Remove old category column
        $this->addSql('ALTER TABLE products DROP COLUMN old_category');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP CONSTRAINT fk_products_category');
        $this->addSql('ALTER TABLE products DROP COLUMN category_id');
        $this->addSql('ALTER TABLE products ADD COLUMN old_category VARCHAR(255)');
        $this->addSql('DROP TABLE categories');
    }

    private function migrateExistingCategories(bool $isSharedDb): void
    {
        if ($isSharedDb) {
            // Migrate categories for shared database
            $this->addSql("
                INSERT INTO categories (tenant_id, name, slug)
                SELECT DISTINCT tenant_id, old_category, LOWER(REPLACE(old_category, ' ', '-'))
                FROM products 
                WHERE old_category IS NOT NULL
            ");

            $this->addSql("
                UPDATE products p 
                SET category_id = (
                    SELECT c.id FROM categories c 
                    WHERE c.tenant_id = p.tenant_id 
                    AND c.name = p.old_category
                )
                WHERE p.old_category IS NOT NULL
            ");
        } else {
            // Migrate categories for multi-database
            $this->addSql("
                INSERT INTO categories (name, slug)
                SELECT DISTINCT old_category, LOWER(REPLACE(old_category, ' ', '-'))
                FROM products 
                WHERE old_category IS NOT NULL
            ");

            $this->addSql("
                UPDATE products p 
                SET category_id = (
                    SELECT c.id FROM categories c 
                    WHERE c.name = p.old_category
                )
                WHERE p.old_category IS NOT NULL
            ");
        }
    }

    private function isSharedDatabaseStrategy(): bool
    {
        return $_ENV['TENANT_DB_STRATEGY'] === 'shared_db';
    }
}
```

## Running Migrations

### Basic Commands

```bash
# Run migrations for current strategy
php bin/console tenant:migrate

# Run migrations with dry-run (show SQL without executing)
php bin/console tenant:migrate --dry-run

# Run migrations for specific tenant (multi-db only)
php bin/console tenant:migrate --tenant=acme

# Allow execution even if no migrations found
php bin/console tenant:migrate --allow-no-migration
```

### Advanced Commands

```bash
# Run migrations up to specific version
php bin/console tenant:migrate --to=20240101120000

# Run migrations from specific version
php bin/console tenant:migrate --from=20240101120000

# Execute only one migration
php bin/console tenant:migrate --single

# Show migration status
php bin/console tenant:migrations:status

# Show migration status for specific tenant
php bin/console tenant:migrations:status --tenant=acme
```

### Rollback Commands

```bash
# Rollback to previous version
php bin/console tenant:migrations:rollback

# Rollback to specific version
php bin/console tenant:migrations:rollback --to=20240101120000

# Rollback specific tenant (multi-db only)
php bin/console tenant:migrations:rollback --tenant=acme --to=20240101120000
```

## Migration Strategies

### Shared Database Migrations

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\Migrations\DependencyFactory;

#[AsCommand(name: 'app:migrate-shared-db')]
class MigrateSharedDbCommand extends Command
{
    public function __construct(
        private DependencyFactory $dependencyFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Shared Database Migration');
        $io->note('Running migrations on shared database with tenant filtering');

        try {
            $migrator = $this->dependencyFactory->getMigrator();
            $migrations = $this->dependencyFactory->getMigrationRepository()->getMigrations();

            if ($migrations->count() === 0) {
                $io->success('No migrations to execute');
                return Command::SUCCESS;
            }

            $result = $migrator->migrate();
            
            $io->success(sprintf(
                'Successfully executed %d migrations on shared database',
                count($result->getMigrations())
            ));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

### Multi-Database Migrations

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;

#[AsCommand(name: 'app:migrate-multi-db')]
class MigrateMultiDbCommand extends Command
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private TenantContextInterface $tenantContext,
        private TenantConnectionResolverInterface $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Migrate specific tenant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantSlug = $input->getOption('tenant');

        $tenants = $tenantSlug 
            ? [$this->tenantRegistry->getBySlug($tenantSlug)]
            : $this->tenantRegistry->getAll();

        if (empty($tenants)) {
            $io->warning('No tenants found to migrate');
            return Command::SUCCESS;
        }

        $io->title('Multi-Database Migration');
        $io->note(sprintf('Migrating %d tenant(s)', count($tenants)));

        $successCount = 0;
        $failureCount = 0;

        foreach ($tenants as $tenant) {
            $io->section(sprintf('Migrating tenant: %s', $tenant->getSlug()));

            try {
                // Set tenant context
                $this->tenantContext->setTenant($tenant);

                // Switch to tenant database
                $this->connectionResolver->switchToTenantConnection($tenant);

                // Run migrations for this tenant
                $this->runMigrationsForTenant($tenant, $io);

                $successCount++;
                $io->success(sprintf('Migrations completed for tenant: %s', $tenant->getSlug()));

            } catch (\Exception $e) {
                $failureCount++;
                $io->error(sprintf(
                    'Migration failed for tenant %s: %s',
                    $tenant->getSlug(),
                    $e->getMessage()
                ));
            } finally {
                $this->tenantContext->clear();
            }
        }

        $io->section('Migration Summary');
        $io->table(
            ['Status', 'Count'],
            [
                ['Successful', $successCount],
                ['Failed', $failureCount],
                ['Total', count($tenants)],
            ]
        );

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runMigrationsForTenant(TenantInterface $tenant, SymfonyStyle $io): void
    {
        // Create tenant-specific dependency factory
        $dependencyFactory = $this->createTenantDependencyFactory($tenant);
        
        $migrator = $dependencyFactory->getMigrator();
        $migrations = $dependencyFactory->getMigrationRepository()->getMigrations();

        if ($migrations->count() === 0) {
            $io->note('No migrations to execute for this tenant');
            return;
        }

        $result = $migrator->migrate();
        
        $io->text(sprintf(
            'Executed %d migrations for tenant %s',
            count($result->getMigrations()),
            $tenant->getSlug()
        ));
    }

    private function createTenantDependencyFactory(TenantInterface $tenant): DependencyFactory
    {
        // Implementation to create tenant-specific dependency factory
        // This would use the tenant's database connection parameters
        throw new \RuntimeException('Not implemented');
    }
}
```

## Migration Testing

### Test Migration Rollback

```php
<?php

namespace App\Tests\Migration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;

class MigrationTest extends KernelTestCase
{
    private Connection $connection;
    private DependencyFactory $dependencyFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->connection = $container->get('doctrine.dbal.default_connection');
        $this->dependencyFactory = $container->get(DependencyFactory::class);
    }

    public function testMigrationUpAndDown(): void
    {
        $migrator = $this->dependencyFactory->getMigrator();
        
        // Get current version
        $currentVersion = $this->getCurrentVersion();
        
        // Run migration up
        $migrator->migrate();
        
        // Verify table exists
        $this->assertTrue($this->connection->createSchemaManager()->tablesExist(['products']));
        
        // Run migration down
        $migrator->migrate($currentVersion);
        
        // Verify table is removed
        $this->assertFalse($this->connection->createSchemaManager()->tablesExist(['products']));
    }

    public function testDataMigration(): void
    {
        // Setup test data
        $this->setupTestData();
        
        // Run data migration
        $migrator = $this->dependencyFactory->getMigrator();
        $migrator->migrate();
        
        // Verify data was migrated correctly
        $this->verifyMigratedData();
    }

    private function getCurrentVersion(): ?string
    {
        $repository = $this->dependencyFactory->getMigrationRepository();
        $executed = $repository->getExecutedMigrations();
        
        return $executed->count() > 0 ? $executed->getLast()->getVersion() : null;
    }

    private function setupTestData(): void
    {
        // Insert test data before migration
    }

    private function verifyMigratedData(): void
    {
        // Verify data after migration
    }
}
```

### Integration Test

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantMigrationIntegrationTest extends KernelTestCase
{
    public function testMigrationWithTenantContext(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $tenantRegistry = $container->get(TenantRegistryInterface::class);
        $tenantContext = $container->get(TenantContextInterface::class);

        // Create test tenant
        $tenant = $this->createTestTenant();
        $tenantContext->setTenant($tenant);

        // Run migrations
        $this->runMigrations();

        // Verify tenant-specific data
        $this->verifyTenantData($tenant);
    }

    private function createTestTenant(): TenantInterface
    {
        // Create and return test tenant
        return new class implements TenantInterface {
            public function getId(): ?int { return 1; }
            public function getSlug(): ?string { return 'test-tenant'; }
            public function getName(): ?string { return 'Test Tenant'; }
            // ... other required methods
        };
    }

    private function runMigrations(): void
    {
        // Execute migrations programmatically
    }

    private function verifyTenantData(TenantInterface $tenant): void
    {
        // Verify tenant-specific data exists
    }
}
```

## Best Practices

### 1. Version Control

```bash
# Always commit migrations to version control
git add migrations/
git commit -m "Add product table migration"
```

### 2. Backup Before Migration

```bash
# Backup database before running migrations in production
pg_dump myapp_production > backup_$(date +%Y%m%d_%H%M%S).sql

# Run migrations
php bin/console tenant:migrate --env=prod
```

### 3. Test Migrations

```bash
# Always test migrations in development first
php bin/console tenant:migrate --dry-run

# Test rollback capability
php bin/console tenant:migrations:rollback --dry-run
```

### 4. Monitor Progress

```bash
# Monitor migration progress for large datasets
php bin/console tenant:migrate --verbose

# Check migration status
php bin/console tenant:migrations:status
```

### 5. Handle Failures

```php
// In migration classes, use transactions for data safety
public function up(Schema $schema): void
{
    $this->connection->beginTransaction();
    
    try {
        // Your migration logic here
        $this->addSql('...');
        
        $this->connection->commit();
    } catch (\Exception $e) {
        $this->connection->rollback();
        throw $e;
    }
}
```

## Troubleshooting

### Common Issues

1. **Migration Timeout**: Increase PHP execution time for large migrations
2. **Lock Conflicts**: Ensure no other processes are running migrations
3. **Tenant Not Found**: Verify tenant exists before running tenant-specific migrations
4. **Connection Issues**: Check database connectivity for multi-db setups

### Debug Commands

```bash
# Check migration status
php bin/console tenant:migrations:status --show-versions

# List available migrations
php bin/console tenant:migrations:list

# Show migration SQL
php bin/console tenant:migrations:generate --dry-run

# Verify database schema
php bin/console doctrine:schema:validate
```

### Recovery Commands

```bash
# Mark migration as executed (without running it)
php bin/console tenant:migrations:version --add 20240101120000

# Mark migration as not executed
php bin/console tenant:migrations:version --delete 20240101120000

# Sync migration versions
php bin/console tenant:migrations:sync-metadata-storage
```