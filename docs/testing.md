# Testing Multi-Tenant Applications

Testing multi-tenant applications requires special considerations to ensure tenant isolation, proper context handling, and data integrity across different tenant scenarios.

## Overview

Multi-tenant testing involves:

- **Tenant Context Management**: Setting up proper tenant context in tests
- **Data Isolation**: Ensuring test data doesn't leak between tenants
- **Database Strategy Testing**: Testing both shared-db and multi-db scenarios
- **Service Integration**: Testing tenant-aware services (mailer, messenger, storage)
- **Fixture Management**: Loading tenant-specific test data

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