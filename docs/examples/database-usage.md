# Tenant-Aware Database Usage Examples

## Entity Setup

### 1. Basic Tenant-Owned Entity

```php
// src/Entity/Product.php
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
}
```

### 2. Custom Tenant Entity

```php
// src/Entity/Tenant.php
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\Trait\TenantDatabaseInfoTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant implements TenantInterface
{
    use TenantDatabaseInfoTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
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

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): static
    {
        $this->domain = $domain;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

### 3. Complex Entity with Relations

```php
// src/Entity/Order.php
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $total = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->orderNumber = $this->generateOrderNumber();
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    // Getters and setters...
}

// src/Entity/OrderItem.php
#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    // Getters and setters...
}
```

## Repository Usage

### 1. Basic Repository

```php
// src/Repository/ProductRepository.php
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Find products by category for current tenant.
     * The tenant filter is automatically applied.
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.category = :category')
            ->setParameter('category', $category)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active products with stock for current tenant.
     */
    public function findActiveWithStock(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->andWhere('p.stock > :stock')
            ->setParameter('active', true)
            ->setParameter('stock', 0)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get product statistics for current tenant.
     */
    public function getStatistics(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) as total_products')
            ->addSelect('AVG(p.price) as average_price')
            ->addSelect('SUM(p.stock) as total_stock')
            ->getQuery()
            ->getSingleResult();

        return [
            'total_products' => (int) $result['total_products'],
            'average_price' => (float) $result['average_price'],
            'total_stock' => (int) $result['total_stock'],
        ];
    }
}
```

### 2. Advanced Repository with Custom Queries

```php
// src/Repository/OrderRepository.php
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Find orders by date range for current tenant.
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.createdAt >= :startDate')
            ->andWhere('o.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get monthly sales report for current tenant.
     */
    public function getMonthlySalesReport(int $year): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('MONTH(o.createdAt) as month')
            ->addSelect('COUNT(o.id) as order_count')
            ->addSelect('SUM(o.total) as total_sales')
            ->andWhere('YEAR(o.createdAt) = :year')
            ->andWhere('o.status = :status')
            ->setParameter('year', $year)
            ->setParameter('status', 'completed')
            ->groupBy('month')
            ->orderBy('month', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find top customers by order value for current tenant.
     */
    public function findTopCustomers(int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->select('u.id, u.email, u.firstName, u.lastName')
            ->addSelect('COUNT(o.id) as order_count')
            ->addSelect('SUM(o.total) as total_spent')
            ->join('o.customer', 'u')
            ->andWhere('o.status = :status')
            ->setParameter('status', 'completed')
            ->groupBy('u.id')
            ->orderBy('total_spent', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
```

## Service Layer

### 1. Product Service

```php
// src/Service/ProductService.php
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private ProductRepository $productRepository
    ) {}

    public function createProduct(array $data): Product
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        $product = new Product();
        $product->setName($data['name']);
        $product->setPrice($data['price']);
        $product->setDescription($data['description'] ?? null);
        $product->setTenant($tenant); // Automatically set by TenantEntityListener

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    public function updateProduct(Product $product, array $data): Product
    {
        // Verify product belongs to current tenant (automatic via filter)
        $product->setName($data['name'] ?? $product->getName());
        $product->setPrice($data['price'] ?? $product->getPrice());
        $product->setDescription($data['description'] ?? $product->getDescription());

        $this->entityManager->flush();

        return $product;
    }

    public function deleteProduct(Product $product): void
    {
        // Product ownership is verified automatically via filter
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }

    public function getProductsByCategory(string $category): array
    {
        return $this->productRepository->findByCategory($category);
    }

    public function getProductStatistics(): array
    {
        return $this->productRepository->getStatistics();
    }
}
```

### 2. Order Service

```php
// src/Service/OrderService.php
class OrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository
    ) {}

    public function createOrder(User $customer, array $items): Order
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        $order = new Order();
        $order->setCustomer($customer);
        $order->setTenant($tenant);

        $total = 0;

        foreach ($items as $itemData) {
            $product = $this->productRepository->find($itemData['product_id']);
            if (!$product) {
                throw new \InvalidArgumentException('Product not found: ' . $itemData['product_id']);
            }

            $orderItem = new OrderItem();
            $orderItem->setOrder($order);
            $orderItem->setProduct($product);
            $orderItem->setQuantity($itemData['quantity']);
            $orderItem->setUnitPrice($product->getPrice());
            $orderItem->setTenant($tenant);

            $order->addItem($orderItem);
            $total += $product->getPrice() * $itemData['quantity'];
        }

        $order->setTotal($total);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    public function updateOrderStatus(Order $order, string $status): Order
    {
        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException('Invalid order status: ' . $status);
        }

        $order->setStatus($status);
        $this->entityManager->flush();

        return $order;
    }

    public function getOrdersByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->orderRepository->findByDateRange($startDate, $endDate);
    }

    public function getMonthlySalesReport(int $year): array
    {
        return $this->orderRepository->getMonthlySalesReport($year);
    }
}
```

## Migrations

### 1. Basic Migration

```php
// migrations/Version20240101000000.php
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenants table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenants (
            id SERIAL PRIMARY KEY,
            slug VARCHAR(255) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            domain VARCHAR(255),
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            database_host VARCHAR(255),
            database_port INTEGER,
            database_name VARCHAR(255),
            database_user VARCHAR(255),
            database_password VARCHAR(255)
        )');

        $this->addSql('COMMENT ON COLUMN tenants.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenants');
    }
}
```

### 2. Tenant-Aware Migration

```php
// migrations/Version20240101000001.php
final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create products table with tenant relationship';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE products (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            price NUMERIC(10, 2) NOT NULL,
            description TEXT,
            stock INTEGER NOT NULL DEFAULT 0,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');

        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_8D93D649A0A0C1E5 
            FOREIGN KEY (tenant_id) REFERENCES tenants (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE INDEX IDX_8D93D649A0A0C1E5 ON products (tenant_id)');
        $this->addSql('COMMENT ON COLUMN products.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN products.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP CONSTRAINT FK_8D93D649A0A0C1E5');
        $this->addSql('DROP TABLE products');
    }
}
```

## Fixtures

### 1. Tenant Fixtures

```php
// src/DataFixtures/TenantFixtures.php
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TenantFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tenants = [
            ['slug' => 'acme-corp', 'name' => 'ACME Corporation', 'domain' => 'acme.example.com'],
            ['slug' => 'tech-startup', 'name' => 'Tech Startup Inc', 'domain' => 'tech.example.com'],
            ['slug' => 'retail-store', 'name' => 'Retail Store Ltd', 'domain' => 'retail.example.com'],
        ];

        foreach ($tenants as $index => $tenantData) {
            $tenant = new Tenant();
            $tenant->setSlug($tenantData['slug']);
            $tenant->setName($tenantData['name']);
            $tenant->setDomain($tenantData['domain']);

            $manager->persist($tenant);
            $this->addReference('tenant-' . $index, $tenant);
        }

        $manager->flush();
    }
}
```

### 2. Tenant-Specific Fixtures

```php
// src/DataFixtures/ProductFixtures.php
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Load products for each tenant
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            $this->tenantContext->setTenant($tenant);

            $products = $this->getProductsForTenant($tenantIndex);

            foreach ($products as $productData) {
                $product = new Product();
                $product->setName($productData['name']);
                $product->setPrice($productData['price']);
                $product->setDescription($productData['description']);
                $product->setStock($productData['stock']);
                $product->setTenant($tenant);

                $manager->persist($product);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [TenantFixtures::class];
    }

    private function getProductsForTenant(int $tenantIndex): array
    {
        $productSets = [
            // ACME Corp products
            [
                ['name' => 'ACME Widget', 'price' => '29.99', 'description' => 'Premium widget', 'stock' => 100],
                ['name' => 'ACME Gadget', 'price' => '49.99', 'description' => 'Advanced gadget', 'stock' => 50],
            ],
            // Tech Startup products
            [
                ['name' => 'Tech Solution A', 'price' => '99.99', 'description' => 'Innovative solution', 'stock' => 25],
                ['name' => 'Tech Solution B', 'price' => '149.99', 'description' => 'Enterprise solution', 'stock' => 10],
            ],
            // Retail Store products
            [
                ['name' => 'Retail Item 1', 'price' => '19.99', 'description' => 'Popular item', 'stock' => 200],
                ['name' => 'Retail Item 2', 'price' => '39.99', 'description' => 'Premium item', 'stock' => 75],
            ],
        ];

        return $productSets[$tenantIndex] ?? [];
    }
}
```

## Console Commands

### 1. Database Schema Commands

```bash
# Create schema for all tenants
php bin/console tenant:schema:create

# Create schema for specific tenant
php bin/console tenant:schema:create --tenant=acme-corp

# Drop schema for specific tenant
php bin/console tenant:schema:drop --tenant=acme-corp --force

# Update schema for all tenants
php bin/console tenant:schema:update --force

# Run migrations for all tenants
php bin/console tenant:migrations:migrate

# Run migrations for specific tenant
php bin/console tenant:migrations:migrate --tenant=acme-corp
```

### 2. Fixture Commands

```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures:load

# Load fixtures for specific tenant
php bin/console tenant:fixtures:load --tenant=acme-corp

# Load specific fixture group
php bin/console tenant:fixtures:load --group=products
```

## Testing

### 1. Repository Test

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ProductRepository $repository;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get('doctrine')->getManager();
        $this->repository = $this->entityManager->getRepository(Product::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);
    }

    public function testFindByCategory(): void
    {
        // Set tenant context
        $tenant = $this->createTestTenant();
        $this->tenantContext->setTenant($tenant);

        // Create test products
        $product1 = $this->createTestProduct('Electronics', 'Laptop');
        $product2 = $this->createTestProduct('Electronics', 'Phone');
        $product3 = $this->createTestProduct('Books', 'Novel');

        // Test repository method
        $electronics = $this->repository->findByCategory('Electronics');
        
        $this->assertCount(2, $electronics);
        $this->assertEquals('Laptop', $electronics[0]->getName());
        $this->assertEquals('Phone', $electronics[1]->getName());
    }

    private function createTestTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setSlug('test-tenant');
        $tenant->setName('Test Tenant');

        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        return $tenant;
    }

    private function createTestProduct(string $category, string $name): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->setCategory($category);
        $product->setPrice('99.99');

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }
}
```

### 2. Service Test

```php
class ProductServiceTest extends KernelTestCase
{
    private ProductService $productService;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->productService = $container->get(ProductService::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);
    }

    public function testCreateProduct(): void
    {
        $tenant = $this->createTestTenant();
        $this->tenantContext->setTenant($tenant);

        $productData = [
            'name' => 'Test Product',
            'price' => '29.99',
            'description' => 'Test description',
        ];

        $product = $this->productService->createProduct($productData);

        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals('Test Product', $product->getName());
        $this->assertEquals('29.99', $product->getPrice());
        $this->assertEquals($tenant, $product->getTenant());
    }

    public function testCreateProductWithoutTenant(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant context available');

        $this->productService->createProduct(['name' => 'Test', 'price' => '10.00']);
    }
}
```

## Best Practices

### 1. Entity Design

```php
// Good: Use the trait for consistent implementation
class MyEntity implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;
    
    // Entity-specific properties and methods
}

// Good: Add indexes for performance
#[ORM\Table(name: 'my_entities', indexes: [
    new ORM\Index(name: 'idx_tenant_created', columns: ['tenant_id', 'created_at'])
])]
class MyEntity implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;
}
```

### 2. Query Optimization

```php
// Good: Use joins to avoid N+1 queries
public function findWithRelations(): array
{
    return $this->createQueryBuilder('e')
        ->leftJoin('e.relatedEntity', 'r')
        ->addSelect('r')
        ->getQuery()
        ->getResult();
}

// Good: Use pagination for large datasets
public function findPaginated(int $page, int $limit): array
{
    return $this->createQueryBuilder('e')
        ->setFirstResult(($page - 1) * $limit)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

### 3. Error Handling

```php
class TenantAwareService
{
    public function processEntity($id): void
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        $entity = $this->repository->find($id);
        if (!$entity) {
            throw new \InvalidArgumentException('Entity not found or not accessible');
        }

        // Process entity...
    }
}
```

## Configuration Reference

### Doctrine Configuration

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
                driver: 'pdo_pgsql'
                server_version: '16'
                charset: utf8

    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings:
                    App:
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
                filters:
                    tenant_filter:
                        class: Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
                        enabled: true
```

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver:
        type: 'subdomain'
        options:
            header_name: 'X-Tenant-ID'
    database:
        enabled: true
        dispatch_database_switch: true
        enable_tenant_scope: true
    cache:
        enabled: true
        adapter: 'cache.adapter.redis'
        ttl: 3600
```