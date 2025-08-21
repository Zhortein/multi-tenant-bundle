# Test Kit Usage Examples

> 📖 **Navigation**: [← Storage Usage](storage-usage.md) | [Back to Documentation Index](../index.md) | [Back to Examples](../examples/)

This document provides comprehensive examples of using the Multi-Tenant Bundle Test Kit to test your multi-tenant applications.

## Table of Contents

- [Basic Setup](#basic-setup)
- [HTTP Testing](#http-testing)
- [CLI Testing](#cli-testing)
- [Messenger Testing](#messenger-testing)
- [RLS Isolation Testing](#rls-isolation-testing)
- [Custom Test Scenarios](#custom-test-scenarios)

## Basic Setup

### 1. Using WithTenantTrait

The `WithTenantTrait` is the foundation of the Test Kit. It provides methods to execute code within specific tenant contexts.

```php
<?php

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Tests\Toolkit\WithTenantTrait;

class ProductServiceTest extends TestCase
{
    use WithTenantTrait;

    public function testTenantIsolation(): void
    {
        // Execute code within tenant A context
        $productsA = $this->withTenant('tenant-a', function () {
            return $this->productService->getAllProducts();
        });

        // Execute code within tenant B context
        $productsB = $this->withTenant('tenant-b', function () {
            return $this->productService->getAllProducts();
        });

        // Verify isolation
        $this->assertNotEquals(count($productsA), count($productsB));
    }

    public function testRlsDefenseInDepth(): void
    {
        // Test that RLS works even when Doctrine filters are disabled
        $this->withTenant('tenant-a', function () {
            $this->withoutDoctrineTenantFilter(function () {
                $products = $this->repository->findAll();
                
                // Should still see only tenant A products due to RLS
                foreach ($products as $product) {
                    $this->assertStringContainsString('tenant-a', $product->getName());
                }
            });
        });
    }
}
```

### 2. Using TestData Builder

The `TestData` class provides convenient methods to create test data for your tenant-aware entities.

```php
<?php

namespace App\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class ProductIntegrationTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenants
        $this->getTestData()->seedTenants([
            'acme-corp' => ['name' => 'ACME Corporation'],
            'tech-startup' => ['name' => 'Tech Startup Inc'],
        ]);

        // Seed products for each tenant
        $this->getTestData()->seedProducts('acme-corp', 5);
        $this->getTestData()->seedProducts('tech-startup', 3);
    }

    public function testProductCounts(): void
    {
        // Verify tenant A has 5 products
        $countA = $this->getTestData()->countProductsForTenant('acme-corp');
        $this->assertSame(5, $countA);

        // Verify tenant B has 3 products
        $countB = $this->getTestData()->countProductsForTenant('tech-startup');
        $this->assertSame(3, $countB);
    }

    public function testProductRetrieval(): void
    {
        $productsA = $this->getTestData()->getProductsForTenant('acme-corp');
        $productsB = $this->getTestData()->getProductsForTenant('tech-startup');

        $this->assertCount(5, $productsA);
        $this->assertCount(3, $productsB);

        // Verify no cross-contamination
        foreach ($productsA as $product) {
            $this->assertStringContainsString('acme-corp', $product->getName());
        }

        foreach ($productsB as $product) {
            $this->assertStringContainsString('tech-startup', $product->getName());
        }
    }
}
```

## HTTP Testing

### 1. Subdomain Resolution Testing

```php
<?php

namespace App\Tests\Functional\Controller;

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class ProductControllerTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTestData()->seedTenants([
            'acme' => ['name' => 'ACME Corp'],
            'beta' => ['name' => 'Beta Inc'],
        ]);

        $this->getTestData()->seedProducts('acme', 3);
        $this->getTestData()->seedProducts('beta', 2);
    }

    public function testSubdomainResolution(): void
    {
        // Test ACME subdomain
        $clientAcme = $this->createSubdomainClient('acme');
        $crawler = $clientAcme->request('GET', '/products');

        $this->assertResponseIsSuccessful();
        $response = $clientAcme->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData('acme', $content);
        $this->assertResponseDoesNotContainOtherTenantData(['beta'], $content);

        // Test Beta subdomain
        $clientBeta = $this->createSubdomainClient('beta');
        $crawler = $clientBeta->request('GET', '/products');

        $this->assertResponseIsSuccessful();
        $response = $clientBeta->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData('beta', $content);
        $this->assertResponseDoesNotContainOtherTenantData(['acme'], $content);
    }
}
```

### 2. Header-Based Resolution Testing

```php
<?php

public function testHeaderResolution(): void
{
    // Test with X-Tenant-ID header
    $client = $this->createHeaderClient('acme', 'X-Tenant-ID');
    $crawler = $client->request('GET', '/api/products');

    $this->assertResponseIsSuccessful();
    $response = $client->getResponse();
    $data = json_decode($response->getContent(), true);

    $this->assertArrayHasKey('tenant', $data);
    $this->assertSame('acme', $data['tenant']);
    $this->assertArrayHasKey('products', $data);
    $this->assertCount(3, $data['products']);
}

public function testCustomHeaderResolution(): void
{
    // Test with custom header
    $client = $this->createHeaderClient('beta', 'X-Client-Tenant');
    $crawler = $client->request('GET', '/api/products');

    $this->assertResponseIsSuccessful();
    $response = $client->getResponse();
    $data = json_decode($response->getContent(), true);

    $this->assertSame('beta', $data['tenant']);
    $this->assertCount(2, $data['products']);
}
```

### 3. Path-Based Resolution Testing

```php
<?php

public function testPathResolution(): void
{
    $client = $this->createPathClient();

    // Test ACME path
    $crawler = $this->requestWithTenantPath($client, 'GET', 'acme', '/dashboard');
    
    $this->assertResponseIsSuccessful();
    $this->assertSelectorTextContains('h1', 'ACME Corp Dashboard');

    // Test Beta path
    $crawler = $this->requestWithTenantPath($client, 'GET', 'beta', '/dashboard');
    
    $this->assertResponseIsSuccessful();
    $this->assertSelectorTextContains('h1', 'Beta Inc Dashboard');
}
```

### 4. Domain-Based Resolution Testing

```php
<?php

public function testDomainResolution(): void
{
    // Test custom domain for ACME
    $clientAcme = $this->createDomainClient('acme-corp.com');
    $crawler = $clientAcme->request('GET', '/');

    $this->assertResponseIsSuccessful();
    $this->assertResponseContainsTenantData('acme', $clientAcme->getResponse()->getContent());

    // Test custom domain for Beta
    $clientBeta = $this->createDomainClient('beta-inc.com');
    $crawler = $clientBeta->request('GET', '/');

    $this->assertResponseIsSuccessful();
    $this->assertResponseContainsTenantData('beta', $clientBeta->getResponse()->getContent());
}
```

## CLI Testing

### 1. Command Execution with Tenant Context

```php
<?php

namespace App\Tests\Functional\Command;

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantCliTestCase;

class ProductCommandTest extends TenantCliTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTestData()->seedTenants([
            'acme' => ['name' => 'ACME Corp'],
            'beta' => ['name' => 'Beta Inc'],
        ]);

        $this->getTestData()->seedProducts('acme', 5);
        $this->getTestData()->seedProducts('beta', 3);
    }

    public function testProductListCommand(): void
    {
        // Test with --tenant option
        $commandTester = $this->executeCommandWithTenantOption(
            'app:product:list',
            'acme'
        );

        $this->assertCommandIsSuccessful($commandTester);
        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString('5 products found', $output);
        $this->assertCommandOutputContainsTenant($commandTester, 'acme');
    }

    public function testProductListWithEnvironmentVariable(): void
    {
        // Test with TENANT_ID environment variable
        $commandTester = $this->executeCommandWithTenantEnv(
            'app:product:list',
            'beta'
        );

        $this->assertCommandIsSuccessful($commandTester);
        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString('3 products found', $output);
        $this->assertCommandOutputContainsTenant($commandTester, 'beta');
    }
}
```

### 2. Database Operations in CLI

```php
<?php

public function testDatabaseOperationsInCli(): void
{
    // Test creating products via CLI
    $this->withTenant('acme', function () {
        $product = $this->getTestData()->createProduct(
            'acme',
            'CLI Created Product',
            '99.99'
        );

        $this->assertNotNull($product->getId());
        $this->assertSame('CLI Created Product', $product->getName());
    });

    // Verify isolation
    $this->withTenant('beta', function () {
        $products = $this->getTestData()->getProductsForTenant('beta');
        
        // Should not see the ACME product
        foreach ($products as $product) {
            $this->assertNotSame('CLI Created Product', $product->getName());
        }
    });
}
```

## Messenger Testing

### 1. Message Dispatching with Tenant Context

```php
<?php

namespace App\Tests\Integration\Messenger;

use App\Message\ProductCreatedMessage;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantMessengerTestCase;

class ProductMessageTest extends TenantMessengerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTestData()->seedTenants([
            'acme' => ['name' => 'ACME Corp'],
            'beta' => ['name' => 'Beta Inc'],
        ]);
    }

    public function testMessageDispatchWithTenantStamp(): void
    {
        $message = new ProductCreatedMessage('New Product', '49.99');

        // Dispatch in ACME context
        $envelope = $this->dispatchAndAssertTenantStamp($message, 'acme');

        // Verify the message was queued
        $this->assertTransportMessageCount('async', 1);

        // Verify tenant stamp
        $this->assertEnvelopeHasTenantStamp($envelope, 'acme');
    }

    public function testAsyncMessageProcessing(): void
    {
        $message = new ProductCreatedMessage('Async Product', '79.99');

        // Dispatch message
        $this->dispatchWithTenant($message, 'beta');

        // Verify message is queued
        $messages = $this->getTransportMessages('async');
        $this->assertCount(1, $messages);

        // Verify tenant stamp on queued message
        $this->assertEnvelopeHasTenantStamp($messages[0], 'beta');

        // Simulate worker processing
        $this->processMessagesWithTenant('async', 'beta');

        // Verify message was processed
        $this->assertTransportIsEmpty('async');
    }
}
```

### 2. Message Handler Testing

```php
<?php

public function testMessageHandlerTenantIsolation(): void
{
    $messageA = new ProductCreatedMessage('Product A', '10.00');
    $messageB = new ProductCreatedMessage('Product B', '20.00');

    // Dispatch messages for different tenants
    $this->dispatchWithTenant($messageA, 'acme');
    $this->dispatchWithTenant($messageB, 'beta');

    // Verify both messages are queued with correct tenant stamps
    $messages = $this->getTransportMessages('async');
    $this->assertCount(2, $messages);

    $this->assertEnvelopeHasTenantStamp($messages[0], 'acme');
    $this->assertEnvelopeHasTenantStamp($messages[1], 'beta');

    // Process messages and verify tenant isolation
    $this->processMessagesWithTenant('async', 'acme');
    $this->processMessagesWithTenant('async', 'beta');

    // Verify products were created in correct tenant contexts
    $productsAcme = $this->getTestData()->getProductsForTenant('acme');
    $productsBeta = $this->getTestData()->getProductsForTenant('beta');

    $this->assertCount(1, $productsAcme);
    $this->assertCount(1, $productsBeta);

    $this->assertSame('Product A', $productsAcme[0]->getName());
    $this->assertSame('Product B', $productsBeta[0]->getName());
}
```

## RLS Isolation Testing

### 1. Critical Defense-in-Depth Test

```php
<?php

namespace App\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class RlsIsolationTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTestData()->seedTenants([
            'secure-tenant' => ['name' => 'Secure Tenant'],
            'other-tenant' => ['name' => 'Other Tenant'],
        ]);

        $this->getTestData()->seedProducts('secure-tenant', 3);
        $this->getTestData()->seedProducts('other-tenant', 2);
    }

    /**
     * This is the CRITICAL test that proves RLS works as defense-in-depth.
     * Even when Doctrine filters are disabled, PostgreSQL RLS should still
     * provide tenant isolation.
     */
    public function testRlsIsolationWithDoctrineFilterDisabled(): void
    {
        $repository = $this->getEntityManager()->getRepository(TestProduct::class);

        $this->withTenant('secure-tenant', function () use ($repository) {
            $this->withoutDoctrineTenantFilter(function () use ($repository) {
                // Even with Doctrine filter disabled, RLS should limit results
                $products = $repository->findAll();

                $this->assertCount(
                    3,
                    $products,
                    'RLS should ensure secure-tenant sees only 3 products (not all 5)'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        'secure-tenant',
                        $product->getName(),
                        'RLS should ensure all products belong to secure-tenant'
                    );
                }
            });
        });
    }

    public function testRlsWithNativeSqlQueries(): void
    {
        $connection = $this->getEntityManager()->getConnection();

        $this->withTenant('secure-tenant', function () use ($connection) {
            $this->withoutDoctrineTenantFilter(function () use ($connection) {
                $result = $connection->executeQuery('SELECT * FROM test_products ORDER BY id');
                $products = $result->fetchAllAssociative();

                $this->assertCount(
                    3,
                    $products,
                    'RLS should limit native SQL query results to secure-tenant products only'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        'secure-tenant',
                        $product['name'],
                        'All native SQL query results should belong to secure-tenant'
                    );
                }
            });
        });
    }
}
```

### 2. PostgreSQL Session Variable Testing

```php
<?php

public function testPostgreSqlSessionVariableManagement(): void
{
    $connection = $this->getEntityManager()->getConnection();

    // Initially, no tenant should be set
    $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
    $currentTenantId = $result->fetchOne();
    $this->assertEmpty($currentTenantId, 'Initially, no tenant ID should be set');

    // Test that session variable is set within tenant context
    $tenant = $this->getTenantRegistry()->findBySlug('secure-tenant');
    $this->assertNotNull($tenant);

    $this->withTenant('secure-tenant', function () use ($connection, $tenant) {
        $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
        $currentTenantId = $result->fetchOne();
        $this->assertSame(
            (string) $tenant->getId(),
            $currentTenantId,
            'Session variable should be set to secure-tenant ID'
        );
    });

    // After exiting tenant context, session variable should be cleared
    $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
    $currentTenantId = $result->fetchOne();
    $this->assertEmpty($currentTenantId, 'Session variable should be cleared after exiting tenant context');
}
```

## Custom Test Scenarios

### 1. Multi-Tenant E-commerce Testing

```php
<?php

namespace App\Tests\Integration\Ecommerce;

use App\Entity\Order;
use App\Entity\Customer;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class EcommerceIsolationTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup multiple store tenants
        $this->getTestData()->seedTenants([
            'store-a' => ['name' => 'Electronics Store'],
            'store-b' => ['name' => 'Fashion Store'],
            'store-c' => ['name' => 'Books Store'],
        ]);

        // Seed different product types for each store
        $this->seedElectronicsProducts('store-a');
        $this->seedFashionProducts('store-b');
        $this->seedBookProducts('store-c');
    }

    public function testCrossStoreDataIsolation(): void
    {
        // Test that each store only sees its own products
        $this->withTenant('store-a', function () {
            $products = $this->getTestData()->getProductsForTenant('store-a');
            foreach ($products as $product) {
                $this->assertStringContainsString('Electronics', $product->getCategory());
            }
        });

        $this->withTenant('store-b', function () {
            $products = $this->getTestData()->getProductsForTenant('store-b');
            foreach ($products as $product) {
                $this->assertStringContainsString('Fashion', $product->getCategory());
            }
        });
    }

    public function testOrderProcessingIsolation(): void
    {
        // Create orders in different stores
        $orderA = $this->withTenant('store-a', function () {
            return $this->createTestOrder('Electronics Order');
        });

        $orderB = $this->withTenant('store-b', function () {
            return $this->createTestOrder('Fashion Order');
        });

        // Verify orders are isolated
        $this->withTenant('store-a', function () use ($orderA) {
            $orders = $this->entityManager->getRepository(Order::class)->findAll();
            $this->assertCount(1, $orders);
            $this->assertSame($orderA->getId(), $orders[0]->getId());
        });

        $this->withTenant('store-b', function () use ($orderB) {
            $orders = $this->entityManager->getRepository(Order::class)->findAll();
            $this->assertCount(1, $orders);
            $this->assertSame($orderB->getId(), $orders[0]->getId());
        });
    }

    private function seedElectronicsProducts(string $tenantId): void
    {
        $this->getTestData()->createProduct($tenantId, 'Laptop - Electronics', '999.99');
        $this->getTestData()->createProduct($tenantId, 'Phone - Electronics', '599.99');
    }

    private function seedFashionProducts(string $tenantId): void
    {
        $this->getTestData()->createProduct($tenantId, 'Dress - Fashion', '79.99');
        $this->getTestData()->createProduct($tenantId, 'Shoes - Fashion', '129.99');
    }

    private function seedBookProducts(string $tenantId): void
    {
        $this->getTestData()->createProduct($tenantId, 'Novel - Books', '19.99');
        $this->getTestData()->createProduct($tenantId, 'Textbook - Books', '89.99');
    }
}
```

### 2. SaaS Application Testing

```php
<?php

namespace App\Tests\Integration\Saas;

use App\Entity\User;
use App\Entity\Project;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

class SaasIsolationTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup SaaS company tenants
        $this->getTestData()->seedTenants([
            'company-alpha' => ['name' => 'Alpha Corp', 'plan' => 'premium'],
            'company-beta' => ['name' => 'Beta LLC', 'plan' => 'basic'],
            'company-gamma' => ['name' => 'Gamma Inc', 'plan' => 'enterprise'],
        ]);
    }

    public function testUserIsolationBetweenCompanies(): void
    {
        // Create users in different companies
        $userAlpha = $this->withTenant('company-alpha', function () {
            return $this->createTestUser('alice@alpha.com');
        });

        $userBeta = $this->withTenant('company-beta', function () {
            return $this->createTestUser('bob@beta.com');
        });

        // Verify user isolation
        $this->withTenant('company-alpha', function () use ($userAlpha) {
            $users = $this->entityManager->getRepository(User::class)->findAll();
            $this->assertCount(1, $users);
            $this->assertSame('alice@alpha.com', $users[0]->getEmail());
        });

        $this->withTenant('company-beta', function () use ($userBeta) {
            $users = $this->entityManager->getRepository(User::class)->findAll();
            $this->assertCount(1, $users);
            $this->assertSame('bob@beta.com', $users[0]->getEmail());
        });
    }

    public function testProjectDataIsolation(): void
    {
        // Create projects in different companies
        $this->withTenant('company-alpha', function () {
            $this->createTestProject('Alpha Project 1');
            $this->createTestProject('Alpha Project 2');
        });

        $this->withTenant('company-beta', function () {
            $this->createTestProject('Beta Project 1');
        });

        // Verify project isolation
        $this->withTenant('company-alpha', function () {
            $projects = $this->entityManager->getRepository(Project::class)->findAll();
            $this->assertCount(2, $projects);
            
            foreach ($projects as $project) {
                $this->assertStringStartsWith('Alpha', $project->getName());
            }
        });

        $this->withTenant('company-beta', function () {
            $projects = $this->entityManager->getRepository(Project::class)->findAll();
            $this->assertCount(1, $projects);
            $this->assertStringStartsWith('Beta', $projects[0]->getName());
        });
    }

    private function createTestUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed_password');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createTestProject(string $name): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setDescription('Test project');
        
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        
        return $project;
    }
}
```

## Running the Tests

### Using Make Commands

```bash
# Run all Test Kit tests
make test-kit

# Run specific test categories
make test-rls          # RLS isolation tests
make test-resolvers    # Resolver chain tests
make test-messenger    # Messenger tests
make test-cli          # CLI tests
make test-decorators   # Decorator tests

# Run with PostgreSQL
make test-with-postgres

# Run all tests including Test Kit
make test-all
```

### Using PHPUnit Directly

```bash
# Run specific test class
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php

# Run with specific configuration
vendor/bin/phpunit tests/Integration --configuration phpunit.xml.dist

# Run with coverage
vendor/bin/phpunit tests/Integration --coverage-html coverage/
```

### Environment Variables

```bash
# Set database URL for PostgreSQL tests
export DATABASE_URL="postgresql://test_user:test_password@localhost:5432/multi_tenant_test"

# Run tests with environment
vendor/bin/phpunit tests/Integration/RlsIsolationTest.php
```

This comprehensive Test Kit ensures your multi-tenant application maintains proper tenant isolation at all levels - from HTTP requests to database queries to background job processing.