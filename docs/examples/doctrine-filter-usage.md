# Doctrine Filter Usage Examples

> 📖 **Navigation**: [← Database Usage](database-usage.md) | [Back to Documentation Index](../index.md) | [Resolver Chain Usage →](resolver-chain-usage.md)

This document provides comprehensive examples of using the enhanced Doctrine tenant filter in various scenarios.

## Basic Usage Example

Here's a complete example showing how to use the enhanced filter with different entity types:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Psr\Log\LoggerInterface;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Service demonstrating enhanced Doctrine SQLFilter capabilities.
 */
class DoctrineFilterDemoService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private LoggerInterface $logger
    ) {}

    /**
     * Demonstrates the enhanced filter with various scenarios.
     */
    public function demonstrateEnhancements(): void
    {
        // 1. Setup tenant context
        $tenant = $this->createSampleTenant();
        $this->tenantContext->setTenant($tenant);

        // 2. Configure filter with logger
        $this->configureFilter();

        // 3. Demonstrate different entity types
        $this->demonstrateEntityTypes();

        // 4. Demonstrate complex queries
        $this->demonstrateComplexQueries();

        // 5. Demonstrate parameter typing
        $this->demonstrateParameterTyping();
    }

    private function createSampleTenant(): TenantInterface
    {
        return new class implements TenantInterface {
            public function getId(): string|int { return 123; }
            public function getSlug(): string { return 'demo-tenant'; }
            public function getMailerDsn(): ?string { return null; }
            public function getMessengerDsn(): ?string { return null; }
        };
    }

    private function configureFilter(): void
    {
        $filters = $this->entityManager->getFilters();
        
        if (!$filters->isEnabled('tenant')) {
            $filters->enable('tenant');
        }

        $tenantFilter = $filters->getFilter('tenant');
        $tenantFilter->setParameter('tenant_id', '123');

        // Inject logger for debug information
        if ($tenantFilter instanceof TenantDoctrineFilter) {
            $tenantFilter->setLogger($this->logger);
        }

        $this->logger->info('Tenant filter configured', [
            'tenant_id' => '123',
            'filter_enabled' => true
        ]);
    }

    private function demonstrateEntityTypes(): void
    {
        $this->logger->info('=== Demonstrating Entity Types ===');

        // 1. TenantOwnedEntityInterface - will be filtered
        $this->logger->info('Querying TenantOwnedEntity (will be filtered)');
        $this->executeQuery(DemoProduct::class);

        // 2. AsTenantAware attribute - will be filtered with custom field
        $this->logger->info('Querying AsTenantAware entity (will be filtered)');
        $this->executeQuery(DemoEmployee::class);

        // 3. Non-tenant entity - will be skipped safely
        $this->logger->info('Querying non-tenant entity (will be skipped)');
        $this->executeQuery(DemoGlobalSetting::class);
    }

    private function demonstrateComplexQueries(): void
    {
        $this->logger->info('=== Demonstrating Complex Queries ===');

        // JOIN query with multiple aliases
        $this->logger->info('Executing JOIN query with multiple aliases');
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p', 'o')
            ->from(DemoProduct::class, 'p')
            ->leftJoin('p.orders', 'o')
            ->where('p.active = :active')
            ->setParameter('active', true);

        try {
            $results = $queryBuilder->getQuery()->getResult();
            $this->logger->info('JOIN query executed successfully', [
                'result_count' => count($results)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('JOIN query failed', ['error' => $e->getMessage()]);
        }

        // Subquery example
        $this->logger->info('Executing subquery');
        $subQuery = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(o2.product)')
            ->from(DemoOrder::class, 'o2')
            ->where('o2.total > :minTotal')
            ->getDQL();

        $mainQuery = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(DemoProduct::class, 'p')
            ->where("p.id IN ($subQuery)")
            ->setParameter('minTotal', 100);

        try {
            $results = $mainQuery->getQuery()->getResult();
            $this->logger->info('Subquery executed successfully', [
                'result_count' => count($results)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Subquery failed', ['error' => $e->getMessage()]);
        }
    }

    private function demonstrateParameterTyping(): void
    {
        $this->logger->info('=== Demonstrating Parameter Typing ===');

        // Test with integer tenant ID
        $this->logger->info('Testing integer tenant ID');
        $this->reconfigureFilter('123', 'integer');

        // Test with UUID tenant ID
        $this->logger->info('Testing UUID tenant ID');
        $this->reconfigureFilter('550e8400-e29b-41d4-a716-446655440000', 'uuid');

        // Test with string tenant ID
        $this->logger->info('Testing string tenant ID');
        $this->reconfigureFilter('tenant-slug-123', 'string');
    }

    private function executeQuery(string $entityClass): void
    {
        try {
            $repository = $this->entityManager->getRepository($entityClass);
            $results = $repository->findAll();
            
            $this->logger->info('Query executed', [
                'entity' => $entityClass,
                'result_count' => count($results)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Query failed', [
                'entity' => $entityClass,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function reconfigureFilter(string $tenantId, string $type): void
    {
        $filters = $this->entityManager->getFilters();
        $tenantFilter = $filters->getFilter('tenant');
        $tenantFilter->setParameter('tenant_id', $tenantId);

        $this->logger->info('Filter reconfigured', [
            'tenant_id' => $tenantId,
            'type' => $type
        ]);

        // Execute a test query to see the parameter in action
        $this->executeQuery(DemoProduct::class);
    }
}
```

## Example Entities

### Product Entity (TenantOwnedEntityInterface)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'demo_products')]
class DemoProduct implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: DemoOrder::class)]
    private iterable $orders = [];

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function getTenant(): ?TenantInterface { return $this->tenant; }
    public function setTenant(TenantInterface $tenant): void { $this->tenant = $tenant; }
    public function getOrders(): iterable { return $this->orders; }
}
```

### Employee Entity (AsTenantAware Attribute)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'demo_employees')]
#[AsTenantAware(tenantField: 'organization')]
class DemoEmployee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $organization = null;

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getOrganization(): ?TenantInterface { return $this->organization; }
    public function setOrganization(TenantInterface $organization): void { $this->organization = $organization; }
}
```

### Non-Tenant Entity (Will be skipped)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'demo_global_settings')]
class DemoGlobalSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $key = null;

    #[ORM\Column(type: 'text')]
    private ?string $value = null;

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getKey(): ?string { return $this->key; }
    public function setKey(string $key): void { $this->key = $key; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(string $value): void { $this->value = $value; }
}
```

## Repository Examples

### Basic Repository with Automatic Filtering

```php
<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\DemoProduct;

class DemoProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemoProduct::class);
    }

    /**
     * Find active products - automatically filtered by tenant
     */
    public function findActiveProducts(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
        // Automatically adds: AND p.tenant_id = :tenant_id
    }

    /**
     * Complex query with joins - all entities filtered by tenant
     */
    public function findProductsWithOrders(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.orders', 'o')
            ->addSelect('o')
            ->where('p.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
        // Both products and orders filtered by tenant
    }

    /**
     * Subquery example - all parts filtered by tenant
     */
    public function findProductsWithRecentOrders(): array
    {
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(o2.product)')
            ->from('App\Entity\DemoOrder', 'o2')
            ->where('o2.createdAt > :date')
            ->getDQL();

        return $this->createQueryBuilder('p')
            ->where("p.id IN ($subQuery)")
            ->setParameter('date', new \DateTime('-30 days'))
            ->getQuery()
            ->getResult();
        // Both main query and subquery filtered by tenant
    }
}
```

## Service Layer Examples

### Multi-Entity Service

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\DemoProduct;
use App\Entity\DemoEmployee;
use App\Entity\DemoGlobalSetting;

class MultiEntityService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Query multiple entity types - some filtered, some not
     */
    public function getDashboardData(): array
    {
        // These will be filtered by tenant
        $products = $this->entityManager->getRepository(DemoProduct::class)->findAll();
        $employees = $this->entityManager->getRepository(DemoEmployee::class)->findAll();
        
        // This will NOT be filtered (global settings)
        $settings = $this->entityManager->getRepository(DemoGlobalSetting::class)->findAll();

        return [
            'products' => $products,
            'employees' => $employees,
            'global_settings' => $settings, // All tenants' global settings
        ];
    }

    /**
     * Mixed query with tenant-aware and global entities
     */
    public function getProductsWithGlobalConfig(): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 's')
            ->from(DemoProduct::class, 'p')        // Filtered by tenant
            ->leftJoin(DemoGlobalSetting::class, 's', 'WITH', 's.key = :key')  // Not filtered
            ->setParameter('key', 'product_config')
            ->getQuery();

        return $query->getResult(); // Products filtered, settings global
    }
}
```

## Testing Examples

### Integration Test

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\DemoProduct;
use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantFilterIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);
    }

    public function testFilterWorksWithRepositoryQueries(): void
    {
        // Create test tenants
        $tenant1 = $this->createTenant(1, 'tenant-1');
        $tenant2 = $this->createTenant(2, 'tenant-2');

        // Create products for each tenant
        $this->createProduct('Product 1', $tenant1);
        $this->createProduct('Product 2', $tenant2);

        // Set tenant context
        $this->tenantContext->setTenant($tenant1);

        // Query should only return tenant1's products
        $products = $this->entityManager
            ->getRepository(DemoProduct::class)
            ->findAll();

        $this->assertCount(1, $products);
        $this->assertSame('Product 1', $products[0]->getName());
        $this->assertSame(1, $products[0]->getTenant()->getId());
    }

    public function testFilterWorksWithJoins(): void
    {
        // Setup test data...
        $tenant1 = $this->createTenant(1, 'tenant-1');
        $product = $this->createProduct('Product 1', $tenant1);
        $this->createOrder($product, 100.0, $tenant1);

        // Set tenant context
        $this->tenantContext->setTenant($tenant1);

        // Test query with joins
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p', 'o')
            ->from(DemoProduct::class, 'p')
            ->leftJoin('p.orders', 'o')
            ->where('p.active = :active')
            ->setParameter('active', true);

        $results = $queryBuilder->getQuery()->getResult();
        
        // Should only get tenant1's products and orders
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->getTenant()->getId());
    }

    private function createTenant(int $id, string $slug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setId($id);
        $tenant->setSlug($slug);
        
        $this->entityManager->persist($tenant);
        $this->entityManager->flush();
        
        return $tenant;
    }

    private function createProduct(string $name, Tenant $tenant): DemoProduct
    {
        $product = new DemoProduct();
        $product->setName($name);
        $product->setTenant($tenant);
        
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        
        return $product;
    }
}
```

## Debug Logging Examples

When debug logging is enabled, you'll see messages like:

```
[DEBUG] Entity is not tenant-aware, skipping filter
{
    "entity": "App\\Entity\\DemoGlobalSetting",
    "reason": "not_tenant_aware"
}

[DEBUG] Applied tenant filter constraint
{
    "entity": "App\\Entity\\DemoProduct",
    "alias": "p",
    "column": "tenant_id",
    "type": "integer",
    "constraint": "p.tenant_id = 123"
}

[DEBUG] Applied tenant filter constraint
{
    "entity": "App\\Entity\\DemoEmployee",
    "alias": "e",
    "column": "organization_id",
    "type": "uuid",
    "constraint": "e.organization_id = '550e8400-e29b-41d4-a716-446655440000'"
}
```

These examples demonstrate all the enhanced features of the Doctrine tenant filter, including safe entity inspection, proper parameter typing, complex query support, and comprehensive debug logging.