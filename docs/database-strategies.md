# Database Strategies

The multi-tenant bundle supports two primary database strategies for tenant data isolation: **Shared Database** and **Multi-Database**. Each strategy has its own advantages, trade-offs, and use cases.

> 📖 **Navigation**: [← CLI Commands](cli.md) | [Back to Documentation Index](index.md) | [Doctrine Tenant Filter →](doctrine-tenant-filter.md)

## Overview

| Aspect | Shared Database | Multi-Database |
|--------|----------------|----------------|
| **Data Isolation** | Logical (tenant_id filtering) | Physical (separate databases) |
| **Scalability** | Limited by single DB | Highly scalable |
| **Complexity** | Lower | Higher |
| **Cost** | Lower | Higher |
| **Security** | Good | Excellent |
| **Maintenance** | Easier | More complex |
| **Migrations** | Single migration | Per-tenant migrations |

## Shared Database Strategy

### Overview

In the shared database strategy, all tenants share the same database, and data isolation is achieved through tenant_id filtering at the application level.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        enable_filter: true
        auto_tenant_id: true
        rls:
            enabled: true  # Enable PostgreSQL Row-Level Security for defense-in-depth
            session_variable: 'app.tenant_id'
            policy_name_prefix: 'tenant_isolation'
```

### Entity Setup

Entities must include a tenant relationship:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Doctrine\TenantAwareEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[AsTenantAware] // Marks entity as tenant-aware
class Product
{
    use TenantAwareEntityTrait; // Adds tenant relationship

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    // Standard getters and setters...
}
```

### Database Schema

```sql
-- Tenants table
CREATE TABLE tenants (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL
);

-- Products table with tenant_id
CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    INDEX idx_products_tenant (tenant_id)
);

-- Orders table with tenant_id
CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    order_number VARCHAR(255) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    INDEX idx_orders_tenant (tenant_id),
    UNIQUE KEY unique_order_per_tenant (tenant_id, order_number)
);
```

### Automatic Filtering

All queries are automatically filtered by tenant:

```php
<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use App\Entity\Product;

class ProductRepository extends ServiceEntityRepository
{
    public function findActiveProducts(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
        // Automatically adds: AND p.tenant_id = :current_tenant_id
    }
}
```

### Migrations

Single migration for all tenants:

```php
<?php

// migrations/Version20240101000001.php
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE products (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP NOT NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        )');
        
        $this->addSql('CREATE INDEX idx_products_tenant ON products (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE products');
    }
}
```

### Row-Level Security (PostgreSQL)

For additional security with PostgreSQL, you can enable Row-Level Security (RLS):

```bash
# Generate and apply RLS policies
php bin/console tenant:rls:sync --apply
```

This creates database-level policies that provide defense-in-depth protection:

```sql
-- Enable RLS on tenant-aware tables
ALTER TABLE products ENABLE ROW LEVEL SECURITY;

-- Create isolation policy
CREATE POLICY tenant_isolation_products ON products
    USING (tenant_id::text = current_setting('app.tenant_id', true));
```

See the [RLS Security documentation](rls-security.md) for detailed setup instructions.

### Advantages

1. **Simplicity**: Single database to manage
2. **Cost-effective**: Lower infrastructure costs
3. **Easy backups**: Single backup process
4. **Cross-tenant queries**: Possible when needed
5. **Resource sharing**: Efficient resource utilization
6. **Defense-in-depth**: Optional PostgreSQL RLS provides database-level protection

### Disadvantages

1. **Limited scalability**: Single database bottleneck
2. **Security concerns**: Logical isolation only
3. **Noisy neighbor**: One tenant can affect others
4. **Compliance issues**: May not meet strict data isolation requirements

### Use Cases

- **SaaS applications** with moderate scale
- **Cost-sensitive** deployments
- **Development environments**
- Applications with **cross-tenant reporting** needs
- **Small to medium** tenant counts

## Multi-Database Strategy

### Overview

In the multi-database strategy, each tenant has its own separate database, providing complete physical data isolation.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'multi_db'
        enable_filter: false # Not needed with separate databases
        connection_prefix: 'tenant_'
```

### Entity Setup

Entities don't need tenant_id fields:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Doctrine\MultiDbTenantAwareTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[AsTenantAware(requireTenantId: false)] // No tenant_id needed
class Product
{
    use MultiDbTenantAwareTrait; // Provides tenant context without DB field

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    // Standard getters and setters...
    // No tenant relationship needed
}
```

### Tenant Entity with Database Info

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\Trait\TenantDatabaseInfoTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant implements TenantInterface
{
    use TenantDatabaseInfoTrait; // Adds database connection info

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // Database connection information is provided by the trait:
    // - databaseHost
    // - databasePort  
    // - databaseName
    // - databaseUser
    // - databasePassword

    // Standard getters and setters...
}
```

### Database Schema

Each tenant has its own database:

```sql
-- Master database (contains tenant info)
CREATE DATABASE app_master;
USE app_master;

CREATE TABLE tenants (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    database_host VARCHAR(255),
    database_port INTEGER,
    database_name VARCHAR(255),
    database_user VARCHAR(255),
    database_password VARCHAR(255),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL
);

-- Tenant-specific databases
CREATE DATABASE tenant_acme;
CREATE DATABASE tenant_techstartup;

-- Each tenant database has the same schema (no tenant_id)
USE tenant_acme;
CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    order_number VARCHAR(255) NOT NULL UNIQUE,
    total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL
);
```

### Connection Management

```php
<?php

namespace App\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;

class TenantDatabaseService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantConnectionResolverInterface $connectionResolver,
    ) {}

    public function switchToTenantDatabase(): void
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Switch to tenant-specific database connection
        $this->connectionResolver->switchToTenantConnection($tenant);
    }

    public function createTenantDatabase(TenantInterface $tenant): void
    {
        $connectionParams = [
            'host' => $tenant->getDatabaseHost() ?? 'localhost',
            'port' => $tenant->getDatabasePort() ?? 5432,
            'dbname' => $tenant->getDatabaseName(),
            'user' => $tenant->getDatabaseUser(),
            'password' => $tenant->getDatabasePassword(),
            'driver' => 'pdo_pgsql',
        ];

        $connection = DriverManager::getConnection($connectionParams);
        
        // Create database if it doesn't exist
        $schemaManager = $connection->createSchemaManager();
        
        if (!$schemaManager->databaseExists($tenant->getDatabaseName())) {
            $schemaManager->createDatabase($tenant->getDatabaseName());
        }
    }
}
```

### Migrations

Separate migrations for each tenant:

```bash
# Run migrations for all tenants
php bin/console tenant:migrate

# Run migrations for specific tenant
php bin/console tenant:migrate --tenant=acme

# Create schema for new tenant
php bin/console tenant:schema:create --tenant=acme
```

### Advantages

1. **Complete isolation**: Physical data separation
2. **Scalability**: Each tenant can scale independently
3. **Security**: Maximum data protection
4. **Compliance**: Meets strict regulatory requirements
5. **Performance**: No cross-tenant interference
6. **Customization**: Per-tenant schema modifications possible

### Disadvantages

1. **Complexity**: More complex to manage
2. **Higher costs**: Multiple database instances
3. **Maintenance overhead**: Per-tenant operations
4. **Cross-tenant queries**: Very difficult or impossible
5. **Resource usage**: Higher resource requirements

### Use Cases

- **Enterprise applications** with strict security requirements
- **Regulated industries** (healthcare, finance)
- **Large-scale SaaS** with many tenants
- Applications requiring **tenant-specific customizations**
- **High-security** environments

## Hybrid Approach

### Overview

Some applications benefit from a hybrid approach, using different strategies for different types of data.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'hybrid'
        shared_entities: ['App\Entity\User', 'App\Entity\Audit']
        isolated_entities: ['App\Entity\Product', 'App\Entity\Order']
```

### Implementation

```php
<?php

namespace App\Entity;

// Shared across tenants (in master database)
#[ORM\Entity]
#[AsTenantAware] // Uses tenant_id filtering
class User
{
    use TenantAwareEntityTrait;
    // User data shared or needs cross-tenant access
}

// Isolated per tenant (in tenant databases)
#[ORM\Entity]
#[AsTenantAware(requireTenantId: false)] // No tenant_id needed
class Product
{
    use MultiDbTenantAwareTrait;
    // Product data completely isolated
}
```

## Migration Between Strategies

### From Shared to Multi-Database

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateToMultiDbCommand extends Command
{
    protected static $defaultName = 'tenant:migrate-to-multi-db';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Create tenant-specific databases
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            $this->createTenantDatabase($tenant);
        }

        // 2. Migrate data from shared database
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            $this->migrateDataToTenantDatabase($tenant);
        }

        // 3. Update entity mappings (remove tenant_id)
        // 4. Update configuration
        // 5. Test and verify

        return Command::SUCCESS;
    }
}
```

### From Multi-Database to Shared

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;

class MigrateToSharedDbCommand extends Command
{
    protected static $defaultName = 'tenant:migrate-to-shared-db';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Create shared database schema with tenant_id
        $this->createSharedSchema();

        // 2. Migrate data from tenant databases
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            $this->migrateDataFromTenantDatabase($tenant);
        }

        // 3. Update entity mappings (add tenant_id)
        // 4. Update configuration
        // 5. Test and verify

        return Command::SUCCESS;
    }
}
```

## Performance Considerations

### Shared Database

```sql
-- Essential indexes for shared database
CREATE INDEX idx_products_tenant_id ON products (tenant_id);
CREATE INDEX idx_products_tenant_active ON products (tenant_id, active);
CREATE INDEX idx_orders_tenant_status ON orders (tenant_id, status);
CREATE INDEX idx_users_tenant_email ON users (tenant_id, email);

-- Partitioning by tenant (PostgreSQL)
CREATE TABLE products_partitioned (
    id SERIAL,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL
) PARTITION BY HASH (tenant_id);

CREATE TABLE products_part_0 PARTITION OF products_partitioned
    FOR VALUES WITH (MODULUS 4, REMAINDER 0);
CREATE TABLE products_part_1 PARTITION OF products_partitioned
    FOR VALUES WITH (MODULUS 4, REMAINDER 1);
-- ... more partitions
```

### Multi-Database

```yaml
# Connection pooling configuration
doctrine:
    dbal:
        connections:
            default:
                # Master database connection
                url: '%env(DATABASE_URL)%'
                
            tenant_pool:
                # Tenant connection pool
                wrapper_class: 'App\Doctrine\TenantConnectionWrapper'
                pool_size: 10
                max_connections: 100
```

## Security Considerations

### Shared Database

```php
<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantSecurityChecker
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function checkTenantAccess(object $entity): void
    {
        $currentTenant = $this->tenantContext->getTenant();
        
        if (!$currentTenant) {
            throw new AccessDeniedException('No tenant context');
        }

        if (method_exists($entity, 'getTenant')) {
            $entityTenant = $entity->getTenant();
            
            if ($entityTenant && $entityTenant->getId() !== $currentTenant->getId()) {
                throw new AccessDeniedException('Access denied to entity from different tenant');
            }
        }
    }
}
```

### Multi-Database

```php
<?php

namespace App\Security;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DatabaseSecurityChecker
{
    public function validateDatabaseAccess(TenantInterface $tenant): void
    {
        // Validate tenant is active
        if (!$tenant->isActive()) {
            throw new AccessDeniedException('Tenant is not active');
        }

        // Validate database credentials
        if (!$this->validateDatabaseCredentials($tenant)) {
            throw new AccessDeniedException('Invalid database credentials');
        }

        // Additional security checks
        $this->checkTenantPermissions($tenant);
    }
}
```

## Monitoring and Maintenance

### Shared Database Monitoring

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class SharedDatabaseMonitor
{
    public function getTenantStatistics(): array
    {
        $stats = [];
        
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            $stats[$tenant->getSlug()] = [
                'product_count' => $this->getProductCount($tenant),
                'order_count' => $this->getOrderCount($tenant),
                'storage_usage' => $this->getStorageUsage($tenant),
            ];
        }
        
        return $stats;
    }
}
```

### Multi-Database Monitoring

```php
<?php

namespace App\Service;

class MultiDatabaseMonitor
{
    public function checkTenantDatabases(): array
    {
        $status = [];
        
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            try {
                $connection = $this->connectionResolver->getConnection($tenant);
                $connection->connect();
                
                $status[$tenant->getSlug()] = [
                    'status' => 'healthy',
                    'connection_time' => $this->measureConnectionTime($tenant),
                    'database_size' => $this->getDatabaseSize($tenant),
                ];
            } catch (\Exception $e) {
                $status[$tenant->getSlug()] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $status;
    }
}
```

## Best Practices

### Choosing the Right Strategy

1. **Start with Shared Database** for most applications
2. **Use Multi-Database** for:
   - High security requirements
   - Large scale (1000+ tenants)
   - Regulatory compliance needs
   - Performance isolation requirements

### Implementation Guidelines

1. **Plan for Growth**: Consider future scaling needs
2. **Test Both Strategies**: Prototype with your data model
3. **Monitor Performance**: Set up proper monitoring
4. **Document Decisions**: Record why you chose a strategy
5. **Plan Migration Path**: Design for potential strategy changes

### Common Pitfalls

1. **Premature Optimization**: Don't choose multi-db without clear need
2. **Insufficient Indexing**: Always index tenant_id in shared database
3. **Missing Validation**: Always validate tenant access
4. **Poor Connection Management**: Use connection pooling for multi-db
5. **Inadequate Testing**: Test tenant isolation thoroughly

## Troubleshooting

### Shared Database Issues

```bash
# Check tenant filtering
php bin/console debug:doctrine:filters

# Verify indexes
php bin/console doctrine:schema:validate

# Check query performance
php bin/console doctrine:query:sql "EXPLAIN SELECT * FROM products WHERE tenant_id = 1"
```

### Multi-Database Issues

```bash
# Test tenant connections
php bin/console tenant:connection:test --tenant=acme

# Check database status
php bin/console tenant:database:status

# Verify schema consistency
php bin/console tenant:schema:validate --all-tenants
```

---

> 📖 **Navigation**: [← CLI Commands](cli.md) | [Back to Documentation Index](index.md) | [Doctrine Tenant Filter →](doctrine-tenant-filter.md)