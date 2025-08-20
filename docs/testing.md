# Testing Multi-Tenant Applications

This guide covers testing strategies and tools for multi-tenant applications using the Zhortein Multi-Tenant Bundle.

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Examples →](examples/)

## Test Kit Overview

The bundle includes a comprehensive test kit that provides:

- **TenantWebTestCase**: Base class for integration tests
- **TenantKernelTestCase**: Base class for kernel tests  
- **Tenant stubs and mocks**: For unit testing
- **Database isolation**: Automatic test data cleanup
- **Fixture management**: Tenant-aware fixture loading

## Basic Testing Setup

### Installation

The test kit is included with the bundle. Enable it in your test environment:

```yaml
# config/packages/test/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    fixtures:
        enabled: true
    database:
        strategy: 'shared_db'
        enable_filter: true
```

### Test Base Classes

#### Web Tests

```php
<?php

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class ProductControllerTest extends TenantWebTestCase
{
    public function testProductList(): void
    {
        $tenant = $this->createTestTenant('test-tenant');
        $this->setCurrentTenant($tenant);
        
        $client = static::createClient();
        $client->request('GET', '/products');
        
        $this->assertResponseIsSuccessful();
    }
}
```

#### Kernel Tests

```php
<?php

use Zhortein\MultiTenantBundle\Test\TenantKernelTestCase;

class ProductServiceTest extends TenantKernelTestCase
{
    public function testProductCreation(): void
    {
        $tenant = $this->createTestTenant('service-test');
        $this->setCurrentTenant($tenant);
        
        $productService = static::getContainer()->get(ProductService::class);
        $product = $productService->create(['name' => 'Test Product']);
        
        $this->assertEquals($tenant->getId(), $product->getTenantId());
    }
}
```

## Unit Testing

### Testing Services

```php
<?php

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Test\TenantStub;

class ProductServiceUnitTest extends TestCase
{
    public function testCreateProduct(): void
    {
        $tenant = new TenantStub('unit-test-tenant');
        
        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->method('getTenant')->willReturn($tenant);
        
        $productService = new ProductService($tenantContext);
        $product = $productService->create(['name' => 'Unit Test Product']);
        
        $this->assertEquals('Unit Test Product', $product->getName());
    }
}
```

### Testing Resolvers

```php
<?php

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver;
use Symfony\Component\HttpFoundation\Request;

class SubdomainResolverTest extends TestCase
{
    public function testSubdomainResolution(): void
    {
        $mockRegistry = $this->createMock(TenantRegistryInterface::class);
        $mockTenant = $this->createMockTenant('test-tenant');
        
        $mockRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($mockTenant);
        
        $resolver = new SubdomainTenantResolver(
            $mockRegistry,
            'example.com',
            ['www', 'api']
        );
        
        $request = Request::create('https://test-tenant.example.com/page');
        $tenant = $resolver->resolveTenant($request);
        
        $this->assertSame($mockTenant, $tenant);
    }
}
```

### Testing Resolver Chain

```php
<?php

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;

class ChainResolverTest extends TestCase
{
    public function testPrecedenceOrder(): void
    {
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        
        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);
        
        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            false // non-strict mode
        );
        
        $result = $chainResolver->resolveTenant(new Request());
        
        // Should return first resolver's result
        $this->assertSame($tenant1, $result);
    }
    
    public function testStrictModeAmbiguity(): void
    {
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        
        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);
        
        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            true // strict mode
        );
        
        $this->expectException(AmbiguousTenantResolutionException::class);
        $chainResolver->resolveTenant(new Request());
    }
    
    private function createMockTenant(string $slug): object
    {
        $tenant = $this->createMock(\Zhortein\MultiTenantBundle\Entity\TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getId')->willReturn(rand(1, 1000));
        return $tenant;
    }
    
    private function createMockResolver($returnTenant): object
    {
        $resolver = $this->createMock(\Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface::class);
        $resolver->method('resolveTenant')->willReturn($returnTenant);
        return $resolver;
    }
}
```

## Integration Testing

### Database Testing

```php
<?php

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class DatabaseIsolationTest extends TenantWebTestCase
{
    public function testTenantDataIsolation(): void
    {
        // Create two tenants
        $tenant1 = $this->createTestTenant('tenant-1');
        $tenant2 = $this->createTestTenant('tenant-2');
        
        // Create data for tenant 1
        $this->setCurrentTenant($tenant1);
        $this->createTestProduct('Product 1');
        
        // Create data for tenant 2
        $this->setCurrentTenant($tenant2);
        $this->createTestProduct('Product 2');
        
        // Verify isolation
        $this->setCurrentTenant($tenant1);
        $products = $this->getProductRepository()->findAll();
        $this->assertCount(1, $products);
        $this->assertEquals('Product 1', $products[0]->getName());
        
        $this->setCurrentTenant($tenant2);
        $products = $this->getProductRepository()->findAll();
        $this->assertCount(1, $products);
        $this->assertEquals('Product 2', $products[0]->getName());
    }
}
```

### API Testing

```php
<?php

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class ApiTest extends TenantWebTestCase
{
    public function testApiWithTenantHeader(): void
    {
        $tenant = $this->createTestTenant('api-tenant');
        
        $client = static::createClient();
        $client->request('GET', '/api/products', [], [], [
            'HTTP_X_TENANT_SLUG' => $tenant->getSlug(),
        ]);
        
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }
    
    public function testApiWithSubdomain(): void
    {
        $tenant = $this->createTestTenant('subdomain-tenant');
        
        $client = static::createClient();
        $client->request('GET', '/api/products', [], [], [
            'HTTP_HOST' => $tenant->getSlug() . '.example.com',
        ]);
        
        $this->assertResponseIsSuccessful();
    }
}
```

### Resolver Chain Integration Testing

```php
<?php

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class ResolverChainIntegrationTest extends TenantWebTestCase
{
    public function testChainResolutionPrecedence(): void
    {
        $tenant = $this->createTestTenant('chain-tenant');
        
        $client = static::createClient();
        
        // Request with multiple resolution methods
        // Chain order: subdomain > header > query
        $client->request('GET', '/api/tenant', ['tenant' => 'wrong-tenant'], [], [
            'HTTP_HOST' => $tenant->getSlug() . '.example.com',
            'HTTP_X_TENANT_SLUG' => 'another-wrong-tenant',
        ]);
        
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        
        // Should resolve via subdomain (highest precedence)
        $this->assertEquals($tenant->getSlug(), $data['tenant_slug']);
    }
    
    public function testChainFallback(): void
    {
        $tenant = $this->createTestTenant('fallback-tenant');
        
        $client = static::createClient();
        
        // Request with only header (subdomain excluded)
        $client->request('GET', '/api/tenant', [], [], [
            'HTTP_HOST' => 'www.example.com', // excluded subdomain
            'HTTP_X_TENANT_SLUG' => $tenant->getSlug(),
        ]);
        
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        
        // Should resolve via header fallback
        $this->assertEquals($tenant->getSlug(), $data['tenant_slug']);
    }
}
```

## Fixture Management

### Tenant-Aware Fixtures

```php
<?php

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Zhortein\MultiTenantBundle\Fixture\TenantAwareFixtureInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class ProductFixtures extends Fixture implements TenantAwareFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // This will be called for each tenant
        $product = new Product();
        $product->setName('Sample Product');
        // Tenant is automatically assigned
        
        $manager->persist($product);
        $manager->flush();
    }
    
    public function supportsTenant(TenantInterface $tenant): bool
    {
        // Only load for specific tenants if needed
        return true;
    }
}
```

### Loading Test Fixtures

```php
<?php

class FixtureTest extends TenantWebTestCase
{
    public function testFixtureLoading(): void
    {
        $tenant = $this->createTestTenant('fixture-tenant');
        $this->setCurrentTenant($tenant);
        
        // Load fixtures for current tenant
        $this->loadFixtures([ProductFixtures::class]);
        
        $products = $this->getProductRepository()->findAll();
        $this->assertGreaterThan(0, count($products));
    }
}
```

## Performance Testing

### Load Testing

```php
<?php

class PerformanceTest extends TenantWebTestCase
{
    public function testTenantResolutionPerformance(): void
    {
        $tenant = $this->createTestTenant('perf-tenant');
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < 100; $i++) {
            $client = static::createClient();
            $client->request('GET', '/api/tenant', [], [], [
                'HTTP_HOST' => $tenant->getSlug() . '.example.com',
            ]);
            $this->assertResponseIsSuccessful();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Assert reasonable performance
        $this->assertLessThan(5.0, $duration, 'Tenant resolution took too long');
    }
    
    public function testResolverChainPerformance(): void
    {
        $tenant = $this->createTestTenant('chain-perf-tenant');
        
        $startTime = microtime(true);
        
        // Test resolver chain performance
        for ($i = 0; $i < 50; $i++) {
            $client = static::createClient();
            $client->request('GET', '/api/tenant', [], [], [
                'HTTP_HOST' => $tenant->getSlug() . '.example.com',
                'HTTP_X_TENANT_SLUG' => $tenant->getSlug(),
            ]);
            $this->assertResponseIsSuccessful();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Chain resolution should still be fast
        $this->assertLessThan(3.0, $duration, 'Resolver chain took too long');
    }
}
```

## Testing Different Strategies

### Multi-Database Strategy

```php
<?php

class MultiDbTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Configure for multi-database testing
        $this->configureMultiDatabase();
    }
    
    public function testMultiDatabaseIsolation(): void
    {
        $tenant1 = $this->createTestTenant('db-tenant-1');
        $tenant2 = $this->createTestTenant('db-tenant-2');
        
        // Each tenant should use different database connection
        $this->setCurrentTenant($tenant1);
        $connection1 = $this->getEntityManager()->getConnection();
        
        $this->setCurrentTenant($tenant2);
        $connection2 = $this->getEntityManager()->getConnection();
        
        $this->assertNotSame($connection1, $connection2);
    }
}
```

### RLS Testing

```php
<?php

class RlsTest extends TenantWebTestCase
{
    public function testRowLevelSecurity(): void
    {
        if (!$this->isRlsEnabled()) {
            $this->markTestSkipped('RLS not enabled');
        }
        
        $tenant1 = $this->createTestTenant('rls-tenant-1');
        $tenant2 = $this->createTestTenant('rls-tenant-2');
        
        // Create data for both tenants
        $this->setCurrentTenant($tenant1);
        $product1 = $this->createTestProduct('RLS Product 1');
        
        $this->setCurrentTenant($tenant2);
        $product2 = $this->createTestProduct('RLS Product 2');
        
        // Test RLS isolation at database level
        $this->setCurrentTenant($tenant1);
        $connection = $this->getEntityManager()->getConnection();
        
        // Direct SQL query should only return tenant 1 data
        $result = $connection->executeQuery('SELECT * FROM products')->fetchAllAssociative();
        $this->assertCount(1, $result);
        $this->assertEquals($product1->getId(), $result[0]['id']);
    }
}
```

## Error Handling Testing

### Testing Exception Scenarios

```php
<?php

class ErrorHandlingTest extends TenantWebTestCase
{
    public function testAmbiguousTenantResolution(): void
    {
        // Configure strict resolver chain
        $this->configureStrictResolverChain();
        
        $tenant1 = $this->createTestTenant('ambiguous-1');
        $tenant2 = $this->createTestTenant('ambiguous-2');
        
        $client = static::createClient();
        
        // Request that would resolve to different tenants
        $client->request('GET', '/api/tenant', ['tenant' => $tenant2->getSlug()], [], [
            'HTTP_X_TENANT_SLUG' => $tenant1->getSlug(),
        ]);
        
        // Should return error response
        $this->assertResponseStatusCodeSame(400);
        
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContains('ambiguous', strtolower($data['error']));
    }
    
    public function testNoTenantFound(): void
    {
        $client = static::createClient();
        
        // Request with no tenant information
        $client->request('GET', '/api/tenant', [], [], [
            'HTTP_HOST' => 'unknown.example.com',
        ]);
        
        // Should handle gracefully
        $this->assertResponseStatusCodeSame(404);
    }
}
```

## Continuous Integration

### GitHub Actions Example

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: test_db
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql
      
      - name: Install dependencies
        run: composer install
      
      - name: Run unit tests
        run: make test-unit
      
      - name: Run integration tests
        env:
          DATABASE_URL: postgresql://postgres:postgres@localhost:5432/test_db
        run: make test-integration
      
      - name: Run resolver tests
        run: make test-resolvers
```

### Makefile Integration

```makefile
# Use existing Makefile commands
test-all: test-unit test-integration test-resolvers

test-with-coverage:
	$(DOCKER_RUN) vendor/bin/phpunit --coverage-html coverage

test-specific:
	$(DOCKER_RUN) vendor/bin/phpunit $(ARGS)
```

## Test Configuration

### Test Environment Config

```yaml
# config/packages/test/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'chain'
    require_tenant: false
    default_tenant: 'test-tenant'
    
    resolver_chain:
        order: ['header', 'subdomain', 'query']
        strict: false
        header_allow_list: ['X-Tenant-Slug', 'X-Test-Tenant']
    
    header:
        name: 'X-Tenant-Slug'
    
    subdomain:
        base_domain: 'example.com'
        excluded_subdomains: ['www', 'api', 'test']
    
    query:
        parameter: 'tenant'
    
    database:
        strategy: 'shared_db'
        enable_filter: true
        rls:
            enabled: false  # Disable for easier testing
    
    fixtures:
        enabled: true
    
    cache:
        ttl: 0  # Disable caching in tests
```

### PHPUnit Configuration

```xml
<!-- phpunit.xml.dist -->
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="Resolvers">
            <file>tests/Integration/ResolverChainTest.php</file>
            <file>tests/Integration/ResolverChainHttpTest.php</file>
        </testsuite>
    </testsuites>
    
    <php>
        <env name="KERNEL_CLASS" value="App\Kernel"/>
        <env name="APP_ENV" value="test"/>
        <env name="DATABASE_URL" value="postgresql://user:pass@localhost/test_db"/>
        <env name="TENANT_TEST_MODE" value="1"/>
    </php>
</phpunit>
```

## Best Practices

### Test Organization

1. **Separate unit and integration tests**
2. **Use descriptive test names**
3. **Test both success and failure scenarios**
4. **Verify tenant isolation in all tests**
5. **Use fixtures for consistent test data**

### Performance Considerations

1. **Use database transactions for faster cleanup**
2. **Cache tenant objects in test setup**
3. **Minimize HTTP requests in integration tests**
4. **Use mocks for external dependencies**

### Security Testing

1. **Test tenant isolation thoroughly**
2. **Verify RLS policies work correctly**
3. **Test access control scenarios**
4. **Validate input sanitization**

This comprehensive testing approach ensures your multi-tenant application works correctly across all scenarios and maintains proper tenant isolation.

---

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Examples →](examples/)