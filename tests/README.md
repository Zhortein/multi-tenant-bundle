# Multi-Tenant Bundle Test Kit

The Test Kit provides comprehensive testing utilities to ensure your multi-tenant application maintains proper tenant isolation at all levels.

## 🎯 Overview

The Test Kit includes:

- **Core Utilities**: `WithTenantTrait` and `TestData` for tenant context management
- **Base Test Classes**: Pre-configured test cases for HTTP, CLI, and Messenger testing
- **Integration Tests**: End-to-end tests proving tenant isolation works
- **RLS Verification**: Critical tests proving PostgreSQL Row-Level Security works as defense-in-depth
- **CI/CD Support**: Docker Compose and GitHub Actions integration

## 🚀 Quick Start

### 1. Basic Usage

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class MyTest extends TenantWebTestCase
{
    public function testTenantIsolation(): void
    {
        // Seed test data
        $this->getTestData()->seedTenants([
            'tenant-a' => ['name' => 'Tenant A'],
            'tenant-b' => ['name' => 'Tenant B'],
        ]);
        
        $this->getTestData()->seedProducts('tenant-a', 2);
        $this->getTestData()->seedProducts('tenant-b', 1);
        
        // Test tenant A sees only its data
        $this->withTenant('tenant-a', function () {
            $products = $this->repository->findAll();
            $this->assertCount(2, $products);
        });
        
        // CRITICAL: Test RLS isolation (defense-in-depth)
        $this->withTenant('tenant-a', function () {
            $this->withoutDoctrineTenantFilter(function () {
                $products = $this->repository->findAll();
                // Should still see only 2 products due to RLS
                $this->assertCount(2, $products);
            });
        });
    }
}
```

### 2. HTTP Testing

```php
<?php

public function testSubdomainResolution(): void
{
    $client = $this->createSubdomainClient('tenant-a');
    $crawler = $client->request('GET', '/products');
    
    $this->assertResponseIsSuccessful();
    $this->assertResponseContainsTenantData('tenant-a', $client->getResponse()->getContent());
}
```

### 3. CLI Testing

```php
<?php

public function testCommandWithTenant(): void
{
    $commandTester = $this->executeCommandWithTenantOption('app:list-products', 'tenant-a');
    
    $this->assertCommandIsSuccessful($commandTester);
    $this->assertCommandOutputContainsTenant($commandTester, 'tenant-a');
}
```

## 🏗️ Test Kit Components

### Core Utilities

#### WithTenantTrait
- `withTenant(string $tenantId, callable $fn): mixed` - Execute code in tenant context
- `withoutDoctrineTenantFilter(callable $fn): mixed` - Disable Doctrine filters temporarily

#### TestData
- `seedTenants(array $data): array` - Create test tenants
- `seedProducts(string $tenantId, int $count): array` - Create test products
- `createProduct()`, `createTenant()` - Individual entity creation
- `countProductsForTenant()`, `getProductsForTenant()` - Data retrieval
- `clearAll()` - Cleanup methods

### Base Test Classes

#### TenantWebTestCase
- HTTP client creation for different resolution strategies
- Response assertion helpers
- Tenant data verification methods

#### TenantCliTestCase  
- Command execution with tenant context
- Output assertion helpers
- Environment variable support

#### TenantMessengerTestCase
- Message dispatching with tenant stamps
- Transport management and verification
- Envelope assertion helpers

### Integration Tests

#### RlsIsolationTest ⭐
**The most critical test** - proves PostgreSQL RLS works as defense-in-depth:
- Doctrine filter ON: Normal tenant isolation
- **Doctrine filter OFF + RLS ON**: Critical test proving RLS isolation
- DQL and native SQL query isolation
- PostgreSQL session variable management

#### ResolverChainHttpTest
- Subdomain, header, path, query, and domain resolution
- Resolver precedence testing
- Context isolation between requests

#### MessengerTenantPropagationTest
- TenantStamp propagation verification
- Async message queuing with tenant context
- Worker middleware tenant context application

#### CliTenantContextTest
- Command execution with tenant options
- Database operations in CLI context
- Environment variable support

#### DecoratorsTest
- Cache decorator with tenant prefixing
- Monolog processor tenant information
- Storage helper path isolation

#### ResolverChainTest
- Resolver order precedence
- Strict mode exception handling
- Header allow-list enforcement

## 🐘 PostgreSQL Setup

### Using Docker Compose

```bash
# Start PostgreSQL for testing
cd tests && docker-compose up -d postgres

# Wait for PostgreSQL to be ready
docker-compose exec postgres pg_isready -U test_user -d multi_tenant_test

# Connect to PostgreSQL shell
docker-compose exec postgres psql -U test_user -d multi_tenant_test

# Stop PostgreSQL
docker-compose down
```

### Manual Setup

```sql
-- Create test database
CREATE DATABASE multi_tenant_test;

-- Create test user
CREATE USER test_user WITH PASSWORD 'test_password';
GRANT ALL PRIVILEGES ON DATABASE multi_tenant_test TO test_user;

-- Run initialization script
\i tests/sql/init.sql
```

## 🧪 Running Tests

### Using Make Commands

```bash
# Run all Test Kit tests
make test-kit

# Run specific test categories
make test-rls          # RLS isolation tests (requires PostgreSQL)
make test-resolvers    # Resolver chain tests
make test-messenger    # Messenger tests
make test-cli          # CLI tests
make test-decorators   # Decorator tests

# PostgreSQL management
make postgres-start    # Start PostgreSQL container
make postgres-stop     # Stop PostgreSQL container
make postgres-shell    # Connect to PostgreSQL shell

# Run RLS tests with PostgreSQL
make test-with-postgres
```

### Using PHPUnit Directly

```bash
# Run all integration tests
vendor/bin/phpunit tests/Integration

# Run specific test class
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php

# Run with Test Kit configuration
vendor/bin/phpunit -c tests/phpunit-testkit.xml

# Run with coverage
vendor/bin/phpunit tests/Integration --coverage-html coverage/
```

### Environment Variables

```bash
# Database configuration
export DATABASE_URL="postgresql://test_user:test_password@localhost:5432/multi_tenant_test"

# Test Kit settings
export TESTKIT_ENABLE_RLS=1           # Enable RLS tests
export TESTKIT_POSTGRES_REQUIRED=1    # Require PostgreSQL

# Symfony test settings
export APP_ENV=test
export APP_DEBUG=1
```

## 🔧 Configuration

### PHPUnit Configuration

The Test Kit includes a specialized PHPUnit configuration (`tests/phpunit-testkit.xml`) with:

- Separate test suites for each component
- PostgreSQL-specific environment variables
- Logging and coverage configuration
- Test Kit bootstrap file

### Bootstrap Configuration

The Test Kit bootstrap (`tests/bootstrap-testkit.php`) provides:

- Environment variable loading
- PostgreSQL availability checking
- RLS configuration verification
- Error reporting setup

## 🎯 Test Categories

### 1. Unit Tests (`tests/Unit/`)
- Service and component testing
- Mock-based testing
- Fast execution

### 2. Integration Tests (`tests/Integration/`)
- End-to-end tenant isolation testing
- Database integration with RLS
- HTTP, CLI, and Messenger testing
- Real service integration

### 3. Fixtures (`tests/Fixtures/`)
- Test entities and controllers
- Message classes
- Test kernel and configuration

### 4. Toolkit (`tests/Toolkit/`)
- Reusable testing utilities
- Base test classes
- Helper traits and builders

## 🔒 Security Testing

### RLS (Row-Level Security) Testing

The Test Kit includes comprehensive RLS testing to prove defense-in-depth:

```php
// This test proves RLS works even when Doctrine filters fail
$this->withTenant('tenant-a', function () {
    $this->withoutDoctrineTenantFilter(function () {
        $products = $this->repository->findAll();
        // Should still see only tenant A products due to RLS
        $this->assertCount(2, $products);
    });
});
```

### Session Variable Management

PostgreSQL session variables are automatically managed:

```sql
-- Set tenant context
SELECT set_config('app.tenant_id', '1', true);

-- Clear tenant context
SELECT set_config('app.tenant_id', NULL, true);
```

## 🚀 CI/CD Integration

### GitHub Actions

The Test Kit includes a complete GitHub Actions workflow (`.github/workflows/test-kit.yml`):

- PostgreSQL service setup
- Multi-PHP version testing
- Comprehensive test execution
- Dependency caching

### Docker Support

All Test Kit commands work with Docker:

```bash
# Run tests in Docker container
docker run --rm -v $(pwd):/app -w /app php:8.3-cli vendor/bin/phpunit tests/Integration

# With PostgreSQL link
docker run --rm --link postgres:postgres -v $(pwd):/app -w /app php:8.3-cli vendor/bin/phpunit tests/Integration/RlsIsolationTest.php
```

## 📊 Test Coverage

The Test Kit provides comprehensive coverage of:

- ✅ Tenant context management
- ✅ Database isolation (Doctrine + RLS)
- ✅ HTTP resolution strategies
- ✅ CLI tenant context
- ✅ Messenger tenant propagation
- ✅ Service decorators
- ✅ Resolver chain functionality
- ✅ Error handling and edge cases

## 🤝 Contributing

When adding new tests to the Test Kit:

1. **Use the base classes**: Extend `TenantWebTestCase`, `TenantCliTestCase`, or `TenantMessengerTestCase`
2. **Test isolation**: Always verify tenant data isolation
3. **Test RLS**: Include RLS tests for database operations
4. **Document examples**: Add usage examples to `docs/examples/test-kit-usage.md`
5. **Update CI**: Ensure new tests run in GitHub Actions

## 📚 Documentation

- [Test Kit Usage Examples](../docs/examples/test-kit-usage.md) - Comprehensive usage guide
- [Testing Documentation](../docs/testing.md) - Testing strategies and best practices
- [Bundle Documentation](../docs/) - Complete bundle documentation

## 🆘 Troubleshooting

### PostgreSQL Connection Issues

```bash
# Check if PostgreSQL is running
docker-compose ps

# Check PostgreSQL logs
docker-compose logs postgres

# Test connection manually
docker-compose exec postgres pg_isready -U test_user -d multi_tenant_test
```

### RLS Issues

```bash
# Check if RLS is enabled
docker-compose exec postgres psql -U test_user -d multi_tenant_test -c "SELECT relrowsecurity FROM pg_class WHERE relname = 'test_products';"

# Check RLS policies
docker-compose exec postgres psql -U test_user -d multi_tenant_test -c "SELECT * FROM pg_policies WHERE tablename = 'test_products';"

# Reinitialize database
docker-compose exec postgres psql -U test_user -d multi_tenant_test -f /docker-entrypoint-initdb.d/init.sql
```

### Test Failures

```bash
# Run tests with verbose output
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php --verbose

# Run single test method
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php --filter testRlsIsolationWithDoctrineFilterDisabled

# Check test environment
php -m | grep pdo_pgsql  # Ensure PostgreSQL extension is loaded
```

The Test Kit ensures your multi-tenant application is bulletproof with comprehensive testing at every level! 🛡️