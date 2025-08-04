# Doctrine Tenant Filter

The Doctrine tenant filter automatically adds tenant constraints to database queries, ensuring that entities are filtered by the current tenant context. This provides transparent data isolation without requiring manual filtering in every query.

## How It Works

The tenant filter operates at the SQL level, automatically adding `WHERE` clauses to queries for entities that implement the tenant-aware interface or use the `#[AsTenantAware]` attribute.

### Database Strategies

The filter behavior depends on your database strategy:

- **Shared DB**: Adds `tenant_id` filtering to all queries
- **Multi-DB**: Filter is disabled as each tenant has its own database

## Configuration

### Enable the Filter

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        filters:
            tenant_filter:
                class: Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
                enabled: true
```

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db' # Required for filter to work
        enable_filter: true
        auto_tenant_id: true # Automatically add tenant_id to entities
```

## Entity Setup

### Using the Attribute (Recommended)

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
    use TenantAwareEntityTrait; // Adds tenant relationship and methods

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    // Getters and setters...
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }
}
```

### Using the Interface (Legacy)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $orderNumber = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $total = null;

    // Getters and setters...
}
```

### Custom Tenant Field Name

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[AsTenantAware(tenantField: 'organization')] // Custom field name
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $organization = null; // Custom field name

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // Custom getters and setters for tenant field
    public function getOrganization(): ?TenantInterface
    {
        return $this->organization;
    }

    public function setOrganization(TenantInterface $organization): void
    {
        $this->organization = $organization;
    }

    // Standard getters and setters...
}
```

## Multi-Database Strategy

For multi-database setups, entities don't need tenant_id fields:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Doctrine\MultiDbTenantAwareTrait;

#[ORM\Entity]
#[AsTenantAware(requireTenantId: false)] // No tenant_id field needed
class Product
{
    use MultiDbTenantAwareTrait; // Provides tenant context without DB field

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // No tenant relationship needed - each tenant has its own database
}
```

## Automatic Filtering

### Repository Queries

All repository queries are automatically filtered:

```php
<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Product;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

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

    public function findByCategory(string $category): array
    {
        return $this->findBy(['category' => $category]);
        // Automatically adds tenant filtering
    }

    public function countProducts(): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
        // Automatically filtered by tenant
    }
}
```

### Entity Manager Queries

Direct entity manager queries are also filtered:

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Product;

class ProductService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function getAllProducts(): array
    {
        // Automatically filtered by current tenant
        return $this->entityManager
            ->getRepository(Product::class)
            ->findAll();
    }

    public function findProductById(int $id): ?Product
    {
        // Will only find product if it belongs to current tenant
        return $this->entityManager
            ->getRepository(Product::class)
            ->find($id);
    }

    public function searchProducts(string $query): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        return $qb->select('p')
            ->from(Product::class, 'p')
            ->where('p.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->getQuery()
            ->getResult();
        // Automatically adds: AND p.tenant_id = :tenant_id
    }
}
```

### DQL Queries

Even raw DQL queries are filtered:

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class ReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function getProductStatistics(): array
    {
        $dql = '
            SELECT 
                COUNT(p.id) as total_products,
                AVG(p.price) as average_price,
                SUM(p.stock) as total_stock
            FROM App\Entity\Product p
            WHERE p.active = true
        ';

        return $this->entityManager
            ->createQuery($dql)
            ->getSingleResult();
        // Automatically adds: AND p.tenant_id = :tenant_id
    }
}
```

## Filter Behavior

### SQL Generation

The filter automatically modifies SQL queries:

```sql
-- Original query
SELECT p.id, p.name, p.price FROM products p WHERE p.active = 1;

-- With tenant filter (tenant_id = 123)
SELECT p.id, p.name, p.price FROM products p WHERE p.active = 1 AND p.tenant_id = 123;
```

### Join Queries

The filter works with complex joins:

```php
public function findProductsWithOrders(): array
{
    return $this->createQueryBuilder('p')
        ->leftJoin('p.orders', 'o')
        ->addSelect('o')
        ->getQuery()
        ->getResult();
}
```

Generated SQL:
```sql
SELECT p.*, o.* 
FROM products p 
LEFT JOIN orders o ON p.id = o.product_id 
WHERE p.tenant_id = 123 AND o.tenant_id = 123;
```

### Subqueries

Subqueries are also automatically filtered:

```php
public function findProductsWithRecentOrders(): array
{
    $subQuery = $this->entityManager->createQueryBuilder()
        ->select('IDENTITY(o2.product)')
        ->from(Order::class, 'o2')
        ->where('o2.createdAt > :date')
        ->getDQL();

    return $this->createQueryBuilder('p')
        ->where("p.id IN ($subQuery)")
        ->setParameter('date', new \DateTime('-30 days'))
        ->getQuery()
        ->getResult();
}
```

## Disabling the Filter

### Temporarily Disable

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class AdminService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function getAllProductsAcrossAllTenants(): array
    {
        // Disable tenant filter temporarily
        $this->entityManager->getFilters()->disable('tenant_filter');
        
        try {
            $products = $this->entityManager
                ->getRepository(Product::class)
                ->findAll();
        } finally {
            // Re-enable the filter
            $this->entityManager->getFilters()->enable('tenant_filter');
        }
        
        return $products;
    }
}
```

### Disable for Specific Query

```php
<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class CrossTenantService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function getGlobalStatistics(): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }
        
        try {
            $dql = '
                SELECT 
                    t.slug as tenant_slug,
                    COUNT(p.id) as product_count
                FROM App\Entity\Product p
                JOIN p.tenant t
                GROUP BY t.id
            ';
            
            return $this->entityManager
                ->createQuery($dql)
                ->getResult();
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }
    }
}
```

## Advanced Configuration

### Custom Filter Parameters

```php
<?php

namespace App\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantFilterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return;
        }

        $filters = $this->entityManager->getFilters();
        
        if ($filters->isEnabled('tenant_filter')) {
            $filter = $filters->getFilter('tenant_filter');
            $filter->setParameter('tenant_id', $tenant->getId());
            
            // Add additional parameters if needed
            $filter->setParameter('tenant_active', true);
        }
    }
}
```

### Multiple Tenant Filters

```php
<?php

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class CustomTenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        // Check if entity should be filtered
        if (!$this->shouldFilter($targetEntity)) {
            return '';
        }

        $tenantId = $this->getParameter('tenant_id');
        $constraints = [];

        // Standard tenant filtering
        if ($targetEntity->hasAssociation('tenant')) {
            $constraints[] = sprintf('%s.tenant_id = %s', $targetTableAlias, $tenantId);
        }

        // Additional business logic filtering
        if ($targetEntity->hasField('active')) {
            $constraints[] = sprintf('%s.active = 1', $targetTableAlias);
        }

        // Date-based filtering for some entities
        if ($targetEntity->getName() === 'App\Entity\TemporaryData') {
            $constraints[] = sprintf(
                '%s.expires_at > NOW()', 
                $targetTableAlias
            );
        }

        return implode(' AND ', $constraints);
    }

    private function shouldFilter(ClassMetadata $targetEntity): bool
    {
        // Your filtering logic here
        return true;
    }
}
```

## Performance Considerations

### Indexing

Ensure proper database indexes for tenant filtering:

```sql
-- Add indexes for tenant_id columns
CREATE INDEX idx_products_tenant_id ON products (tenant_id);
CREATE INDEX idx_orders_tenant_id ON orders (tenant_id);
CREATE INDEX idx_users_tenant_id ON users (tenant_id);

-- Composite indexes for common queries
CREATE INDEX idx_products_tenant_active ON products (tenant_id, active);
CREATE INDEX idx_orders_tenant_status ON orders (tenant_id, status);
CREATE INDEX idx_users_tenant_email ON users (tenant_id, email);
```

### Query Optimization

```php
<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;

class OptimizedProductRepository extends ServiceEntityRepository
{
    public function findActiveProductsPaginated(int $page, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true) // Performance hint
            ->getResult();
    }

    public function findProductsWithCategories(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c') // Avoid N+1 queries
            ->getQuery()
            ->getResult();
    }
}
```

## Testing

### Unit Testing with Filter

```php
<?php

namespace App\Tests\Repository;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Entity\Product;
use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get('doctrine')->getManager();
        $this->tenantContext = $container->get(TenantContextInterface::class);
    }

    public function testFilteringByTenant(): void
    {
        // Create test tenants
        $tenant1 = $this->createTenant('tenant1');
        $tenant2 = $this->createTenant('tenant2');

        // Create products for each tenant
        $product1 = $this->createProduct('Product 1', $tenant1);
        $product2 = $this->createProduct('Product 2', $tenant2);

        // Set tenant context
        $this->tenantContext->setTenant($tenant1);

        // Query products - should only return tenant1's products
        $products = $this->entityManager
            ->getRepository(Product::class)
            ->findAll();

        $this->assertCount(1, $products);
        $this->assertEquals('Product 1', $products[0]->getName());
        $this->assertEquals($tenant1->getId(), $products[0]->getTenant()->getId());
    }

    private function createTenant(string $slug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setSlug($slug);
        $tenant->setName(ucfirst($slug));

        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        return $tenant;
    }

    private function createProduct(string $name, Tenant $tenant): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->setTenant($tenant);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }
}
```

### Integration Testing

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TenantFilterIntegrationTest extends WebTestCase
{
    public function testApiEndpointFiltering(): void
    {
        $client = static::createClient();

        // Make request with tenant context
        $client->request('GET', '/api/products', [], [], [
            'HTTP_HOST' => 'tenant1.example.com'
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        // Verify all returned products belong to tenant1
        foreach ($data['products'] as $product) {
            $this->assertEquals('tenant1', $product['tenant_slug']);
        }
    }
}
```

## Troubleshooting

### Common Issues

1. **Filter Not Applied**: Check if filter is enabled and entity uses correct interface/attribute
2. **Performance Issues**: Add proper database indexes for tenant_id columns
3. **Cross-Tenant Queries**: Temporarily disable filter when needed
4. **Migration Issues**: Ensure tenant_id columns are properly created

### Debug Queries

Enable SQL logging to see generated queries:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        logging: true
        profiling_collect_backtrace: true
```

### Filter Status Check

```php
// Check if filter is enabled
$filters = $entityManager->getFilters();
$isEnabled = $filters->isEnabled('tenant_filter');

// Get filter parameters
if ($isEnabled) {
    $filter = $filters->getFilter('tenant_filter');
    $tenantId = $filter->getParameter('tenant_id');
}
```

## Best Practices

1. **Always Use Indexes**: Add database indexes for tenant_id columns
2. **Test Filtering**: Write tests to verify tenant isolation
3. **Monitor Performance**: Use profiling to identify slow queries
4. **Handle Edge Cases**: Consider what happens when no tenant is set
5. **Document Exceptions**: Clearly document when filter is disabled
6. **Use Attributes**: Prefer `#[AsTenantAware]` over interface implementation
7. **Validate Data**: Ensure tenant_id is set when creating entities