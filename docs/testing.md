# Testing Multi-Tenant Applications

Testing multi-tenant applications requires special considerations to ensure tenant isolation, proper context handling, and data integrity across different tenant scenarios.

## Overview

Multi-tenant testing involves:

- **Tenant Context Management**: Setting up proper tenant context in tests
- **Data Isolation**: Ensuring test data doesn't leak between tenants
- **Database Strategy Testing**: Testing both shared-db and multi-db scenarios
- **Service Integration**: Testing tenant-aware services (mailer, messenger, storage)
- **Fixture Management**: Loading tenant-specific test data
- **RLS Verification**: Proving PostgreSQL Row-Level Security works as defense-in-depth

## Test Kit

The bundle provides a comprehensive Test Kit to make testing multi-tenant applications easy and reliable. The Test Kit includes:

- **WithTenantTrait**: Core trait for tenant context management in tests
- **TestData**: Lightweight test data builders for tenant-aware entities
- **Base Test Classes**: Pre-configured test cases for HTTP, CLI, and Messenger testing
- **Integration Tests**: End-to-end tests proving tenant isolation works

### Using the Test Kit

#### 1. WithTenantTrait

The `WithTenantTrait` provides two essential methods for testing:

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\WithTenantTrait;

class MyTest extends TestCase
{
    use WithTenantTrait;

    public function testTenantIsolation(): void
    {
        // Execute code within tenant A context
        $this->withTenant('tenant-a', function () {
            $products = $this->repository->findAll();
            $this->assertCount(2, $products);
        });

        // Execute code with Doctrine filter disabled (tests RLS)
        $this->withTenant('tenant-a', function () {
            $this->withoutDoctrineTenantFilter(function () {
                $products = $this->repository->findAll();
                // Should still see only tenant A products due to RLS
                $this->assertCount(2, $products);
            });
        });
    }
}
```

#### 2. TestData Builder

The `TestData` class provides methods to seed test data:

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\TestData;

// Seed products for specific tenants
$testData->seedProducts('tenant-a', 2);
$testData->seedProducts('tenant-b', 1);

// Create individual entities
$product = $testData->createProduct('tenant-a', 'Test Product', '99.99');

// Count and retrieve tenant-specific data
$count = $testData->countProductsForTenant('tenant-a');
$products = $testData->getProductsForTenant('tenant-a');
```

#### 3. Base Test Classes

##### TenantWebTestCase

For HTTP/web testing with tenant-aware clients:

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class ProductControllerTest extends TenantWebTestCase
{
    public function testSubdomainResolution(): void
    {
        // Create client with subdomain
        $client = $this->createSubdomainClient('tenant-a');
        $crawler = $client->request('GET', '/products');
        
        $this->assertResponseIsSuccessful();
        $this->assertResponseContainsTenantData('tenant-a', $client->getResponse()->getContent());
    }

    public function testHeaderResolution(): void
    {
        // Create client with header
        $client = $this->createHeaderClient('tenant-b', 'X-Tenant-ID');
        $crawler = $client->request('GET', '/products');
        
        $this->assertResponseIsSuccessful();
    }
}
```

##### TenantCliTestCase

For CLI/console testing:

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantCliTestCase;

class TenantCommandTest extends TenantCliTestCase
{
    public function testCommandWithTenantOption(): void
    {
        $commandTester = $this->executeCommandWithTenantOption(
            'tenant:list',
            'tenant-a'
        );
        
        $this->assertCommandIsSuccessful($commandTester);
        $this->assertCommandOutputContainsTenant($commandTester, 'tenant-a');
    }
}
```

##### TenantMessengerTestCase

For Messenger testing:

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantMessengerTestCase;

class MessengerTest extends TenantMessengerTestCase
{
    public function testMessageWithTenantStamp(): void
    {
        $message = new TestMessage('data');
        
        $envelope = $this->dispatchAndAssertTenantStamp($message, 'tenant-a');
        
        $this->assertEnvelopeHasTenantStamp($envelope, 'tenant-a');
    }
}
```

## RLS Isolation Testing

The Test Kit includes comprehensive tests to prove that PostgreSQL Row-Level Security (RLS) provides defense-in-depth tenant isolation:

### RlsIsolationTest

This critical test verifies:

1. **Doctrine Filter ON**: Normal operation shows only tenant-specific data
2. **Doctrine Filter OFF + RLS ON**: Even with filters disabled, RLS still provides isolation
3. **DQL Queries**: Raw DQL queries respect RLS policies
4. **Native SQL**: Direct SQL queries are also filtered by RLS

```php
<?php

use Zhortein\MultiTenantBundle\Tests\Integration\RlsIsolationTest;

class RlsIsolationTest extends TenantWebTestCase
{
    public function testRlsIsolationWithDoctrineFilterDisabled(): void
    {
        // This is the critical test - proves RLS works as defense-in-depth
        $this->withTenant('tenant-a', function () {
            $this->withoutDoctrineTenantFilter(function () {
                $products = $this->repository->findAll();
                
                // Should still see only tenant A products due to RLS
                $this->assertCount(2, $products);
                
                foreach ($products as $product) {
                    $this->assertStringContainsString('tenant-a', $product->getName());
                }
            });
        });
    }
}
```

### PostgreSQL Session Variable Management

The Test Kit automatically manages PostgreSQL session variables:

```sql
-- Set tenant context
SELECT set_config('app.tenant_id', '1', true);

-- Clear tenant context  
SELECT set_config('app.tenant_id', NULL, true);
```

## CI/CD Integration

### Docker Compose for Testing

The Test Kit includes a Docker Compose setup for PostgreSQL testing:

```yaml
# tests/docker-compose.yml
version: '3.8'

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: multi_tenant_test
      POSTGRES_USER: test_user
      POSTGRES_PASSWORD: test_password
    ports:
      - "5432:5432"
    volumes:
      - ./sql/init.sql:/docker-entrypoint-initdb.d/init.sql
```

### Running Tests with PostgreSQL

```bash
# Start PostgreSQL for testing
cd tests && docker-compose up -d postgres

# Wait for PostgreSQL to be ready
docker-compose exec postgres pg_isready -U test_user -d multi_tenant_test

# Run the Test Kit tests
make test-integration

# Run specific RLS tests
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php

# Clean up
docker-compose down
```

### GitHub Actions Example

```yaml
# .github/workflows/test.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: multi_tenant_test
          POSTGRES_USER: test_user
          POSTGRES_PASSWORD: test_password
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
        ports:
          - 5432:5432
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql
          
      - name: Install dependencies
        run: composer install
        
      - name: Run Test Kit
        run: |
          vendor/bin/phpunit tests/Integration/RlsIsolationTest.php
          vendor/bin/phpunit tests/Integration/ResolverChainHttpTest.php
          vendor/bin/phpunit tests/Integration/MessengerTenantPropagationTest.php
        env:
          DATABASE_URL: postgresql://test_user:test_password@localhost:5432/multi_tenant_test
```

## Test Environment Setup

### Test Configuration

```yaml
# config/packages/test/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver:
        type: 'header'
        options:
            header_name: 'X-Tenant-Slug'
    database:
        strategy: 'shared_db'
        enable_filter: true
    require_tenant: false # Allow tests without tenant context
```

### Test Database Configuration

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_TEST_URL)%'
                driver: 'pdo_pgsql'
                server_version: '16'
                charset: utf8
```

## Unit Testing

### Testing Tenant-Aware Services

```php
<?php

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use App\Service\ProductService;
use App\Repository\ProductRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class ProductServiceTest extends TestCase
{
    private ProductService $productService;
    private ProductRepository $productRepository;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        
        $this->productService = new ProductService(
            $this->productRepository,
            $this->tenantContext
        );
    }

    public function testGetProductsForTenant(): void
    {
        $tenant = $this->createMockTenant('acme');
        $expectedProducts = [$this->createMockProduct('Product 1')];

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $this->productRepository
            ->expects($this->once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn($expectedProducts);

        $result = $this->productService->getProducts();

        $this->assertSame($expectedProducts, $result);
    }

    private function createMockTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getId')->willReturn(1);
        return $tenant;
    }
}
```

## Integration Testing

### Using InMemoryTenantRegistry for Tests

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Test\TenantStub;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantRegistryTest extends KernelTestCase
{
    public function testInMemoryTenantRegistry(): void
    {
        // Create test tenants
        $registry = new InMemoryTenantRegistry([
            new TenantStub('demo'),
            new TenantStub('local'),
            new TenantStub('test'),
        ]);

        // Test registry functionality
        $tenant = $registry->getBySlug('demo');
        $this->assertNotNull($tenant);
        $this->assertEquals('demo', $tenant->getSlug());

        $allTenants = $registry->getAll();
        $this->assertCount(3, $allTenants);
    }

    public function testTenantContextWithInMemoryRegistry(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Override the registry service for testing
        $registry = new InMemoryTenantRegistry([
            new TenantStub('test-tenant'),
        ]);

        $container->set('zhortein_multi_tenant.registry', $registry);

        $tenantContext = $container->get(TenantContextInterface::class);
        $tenant = $registry->getBySlug('test-tenant');
        
        $tenantContext->setTenant($tenant);
        
        $this->assertEquals('test-tenant', $tenantContext->getTenant()->getSlug());
    }
}
```

### Testing with Doctrine Fixtures

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use App\DataFixtures\TenantFixtures;
use App\DataFixtures\ProductFixtures;
use App\Repository\ProductRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductRepositoryIntegrationTest extends KernelTestCase
{
    private ProductRepository $productRepository;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->productRepository = $container->get(ProductRepository::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);

        // Load test fixtures
        $this->loadFixtures([
            TenantFixtures::class,
            ProductFixtures::class,
        ]);
    }

    public function testTenantIsolation(): void
    {
        // Test with first tenant
        $tenant1 = $this->getTenantBySlug('acme');
        $this->tenantContext->setTenant($tenant1);
        $products1 = $this->productRepository->findAll();

        // Test with second tenant
        $tenant2 = $this->getTenantBySlug('tech-startup');
        $this->tenantContext->setTenant($tenant2);
        $products2 = $this->productRepository->findAll();

        // Verify products are different for each tenant
        $this->assertNotEquals(count($products1), count($products2));
        
        // Verify no product IDs overlap
        $ids1 = array_map(fn($p) => $p->getId(), $products1);
        $ids2 = array_map(fn($p) => $p->getId(), $products2);
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    private function loadFixtures(array $fixtureClasses): void
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        
        $loader = new SymfonyFixturesLoader($container);
        $fixtures = [];
        
        foreach ($fixtureClasses as $fixtureClass) {
            $fixtures[] = $container->get($fixtureClass);
        }
        
        $purger = new ORMPurger($entityManager);
        $executor = new ORMExecutor($entityManager, $purger);
        $executor->execute($fixtures);
    }

    private function getTenantBySlug(string $slug): TenantInterface
    {
        $container = static::getContainer();
        $tenantRegistry = $container->get(TenantRegistryInterface::class);
        return $tenantRegistry->getBySlug($slug);
    }
}
```

## Functional Testing

### Testing Controllers with Tenant Context

```php
<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\DataFixtures\TenantFixtures;
use App\DataFixtures\ProductFixtures;

class ProductControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixtures([TenantFixtures::class, ProductFixtures::class]);
    }

    public function testProductListWithTenantHeader(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/products', [], [], [
            'HTTP_X_TENANT_SLUG' => 'acme'
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Products');
        
        // Verify only tenant-specific products are shown
        $this->assertSelectorExists('.product-item');
    }

    public function testProductListWithSubdomain(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/products', [], [], [
            'HTTP_HOST' => 'acme.example.com'
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Products');
    }

    private function loadFixtures(array $fixtureClasses): void
    {
        // Implementation similar to integration test
    }
}
```

## Test Utilities

### Tenant Test Trait

```php
<?php

namespace App\Tests\Traits;

use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Test\TenantStub;

trait TenantTestTrait
{
    protected function createTestTenant(string $slug = 'test-tenant'): TenantInterface
    {
        return new TenantStub($slug);
    }

    protected function setTenantContext(TenantInterface $tenant): void
    {
        $container = static::getContainer();
        $tenantContext = $container->get(TenantContextInterface::class);
        $tenantContext->setTenant($tenant);
    }

    protected function clearTenantContext(): void
    {
        $container = static::getContainer();
        $tenantContext = $container->get(TenantContextInterface::class);
        $tenantContext->clear();
    }
}
```

## Running Tests

### Test Commands

```bash
# Run all tests
php bin/phpunit

# Run specific test suite
php bin/phpunit --testsuite=Unit
php bin/phpunit --testsuite=Integration
php bin/phpunit --testsuite=Functional

# Run tests with coverage
php bin/phpunit --coverage-html coverage/

# Run specific test class
php bin/phpunit tests/Unit/Service/ProductServiceTest.php
```

## Best Practices

### 1. Use InMemoryTenantRegistry for Unit Tests

```php
// Good - use in-memory registry for fast unit tests
$registry = new InMemoryTenantRegistry([
    new TenantStub('demo'),
    new TenantStub('local'),
]);
```

### 2. Always Set Tenant Context

```php
// Good - explicit tenant context
protected function setUp(): void
{
    parent::setUp();
    $this->setTenantContext($this->createTestTenant('acme'));
}
```

### 3. Test Tenant Isolation

```php
public function testTenantIsolation(): void
{
    // Create data for tenant A
    $this->setTenantContext($tenantA);
    $this->createTestData();
    
    // Switch to tenant B
    $this->setTenantContext($tenantB);
    $results = $this->repository->findAll();
    
    // Verify tenant A data is not visible
    $this->assertEmpty($results);
}
```

### 4. Use Fixtures for Complex Scenarios

```php
protected function setUp(): void
{
    parent::setUp();
    $this->loadFixtures([
        TenantFixtures::class,
        UserFixtures::class,
        ProductFixtures::class,
    ]);
}
```

### 5. Test Error Conditions

```php
public function testServiceFailsWithoutTenantContext(): void
{
    $this->clearTenantContext();
    
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No tenant context available');
    
    $this->productService->getProducts();
}
```