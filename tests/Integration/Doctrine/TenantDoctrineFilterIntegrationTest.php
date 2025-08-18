<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Integration tests for TenantDoctrineFilter with real database queries.
 *
 * @covers \Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
 */
final class TenantDoctrineFilterIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $this->logger = static::getContainer()->get(LoggerInterface::class);

        // Create test schema
        $this->createTestSchema();

        // Setup test data
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->dropTestSchema();
        parent::tearDown();
    }

    public function testFilterWorksWithRepositoryQueries(): void
    {
        // Set tenant context
        $tenant1 = new TestTenant(1, 'tenant-1');
        $this->tenantContext->setTenant($tenant1);

        // Enable and configure filter
        $this->enableTenantFilter($tenant1);

        $repository = $this->entityManager->getRepository(TestProduct::class);

        // Test findAll - should only return tenant 1 products
        $products = $repository->findAll();
        $this->assertCount(2, $products);

        foreach ($products as $product) {
            $this->assertSame(1, $product->getTenant()->getId());
        }

        // Test findBy - should only return tenant 1 products
        $activeProducts = $repository->findBy(['active' => true]);
        $this->assertCount(1, $activeProducts);
        $this->assertSame('Product 1', $activeProducts[0]->getName());

        // Test custom query builder
        $queryBuilder = $repository->createQueryBuilder('p')
            ->where('p.price > :price')
            ->setParameter('price', 50)
            ->orderBy('p.name', 'ASC');

        $expensiveProducts = $queryBuilder->getQuery()->getResult();
        $this->assertCount(1, $expensiveProducts);
        $this->assertSame('Product 2', $expensiveProducts[0]->getName());
    }

    public function testFilterWorksWithJoins(): void
    {
        // Set tenant context
        $tenant1 = new TestTenant(1, 'tenant-1');
        $this->tenantContext->setTenant($tenant1);

        // Enable and configure filter
        $this->enableTenantFilter($tenant1);

        // Test query with joins
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p', 'o')
            ->from(TestProduct::class, 'p')
            ->leftJoin('p.orders', 'o')
            ->where('p.active = :active')
            ->setParameter('active', true);

        $results = $queryBuilder->getQuery()->getResult();

        // Should only get tenant 1 products and their orders
        $this->assertCount(1, $results);
        $product = $results[0];
        $this->assertSame(1, $product->getTenant()->getId());

        // Check that joined orders are also filtered
        $orders = $product->getOrders();
        foreach ($orders as $order) {
            $this->assertSame(1, $order->getTenant()->getId());
        }
    }

    public function testFilterWorksWithSubqueries(): void
    {
        // Set tenant context
        $tenant1 = new TestTenant(1, 'tenant-1');
        $this->tenantContext->setTenant($tenant1);

        // Enable and configure filter
        $this->enableTenantFilter($tenant1);

        // Create subquery
        $subQuery = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(o2.product)')
            ->from(TestOrder::class, 'o2')
            ->where('o2.total > :minTotal')
            ->getDQL();

        // Main query using subquery
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(TestProduct::class, 'p')
            ->where("p.id IN ($subQuery)")
            ->setParameter('minTotal', 100);

        $results = $queryBuilder->getQuery()->getResult();

        // Should only get tenant 1 products
        foreach ($results as $product) {
            $this->assertSame(1, $product->getTenant()->getId());
        }
    }

    public function testFilterSkipsEntitiesWithoutTenantColumn(): void
    {
        // Set tenant context
        $tenant1 = new TestTenant(1, 'tenant-1');
        $this->tenantContext->setTenant($tenant1);

        // Enable and configure filter
        $this->enableTenantFilter($tenant1);

        // Query entity without tenant column - should not be filtered
        $nonTenantEntities = $this->entityManager
            ->getRepository(NonTenantEntity::class)
            ->findAll();

        // Should return all entities regardless of tenant
        $this->assertCount(3, $nonTenantEntities);
    }

    public function testFilterWorksWithUuidTenantIds(): void
    {
        // Create UUID tenant
        $uuidTenant = new TestUuidTenant('550e8400-e29b-41d4-a716-446655440000', 'uuid-tenant');
        $this->entityManager->persist($uuidTenant);

        // Create product with UUID tenant
        $uuidProduct = new TestUuidProduct();
        $uuidProduct->setName('UUID Product');
        $uuidProduct->setPrice(99.99);
        $uuidProduct->setTenant($uuidTenant);
        $this->entityManager->persist($uuidProduct);
        $this->entityManager->flush();

        // Set tenant context
        $this->tenantContext->setTenant($uuidTenant);

        // Enable and configure filter
        $this->enableTenantFilter($uuidTenant);

        $products = $this->entityManager
            ->getRepository(TestUuidProduct::class)
            ->findAll();

        $this->assertCount(1, $products);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $products[0]->getTenant()->getId());
    }

    public function testFilterWorksWithCustomTenantField(): void
    {
        // Create organization
        $organization = new TestTenant(99, 'organization');
        $this->entityManager->persist($organization);

        // Create employee with custom tenant field
        $employee = new TestEmployee();
        $employee->setName('John Doe');
        $employee->setOrganization($organization);
        $this->entityManager->persist($employee);
        $this->entityManager->flush();

        // Set tenant context
        $this->tenantContext->setTenant($organization);

        // Enable and configure filter
        $this->enableTenantFilter($organization);

        $employees = $this->entityManager
            ->getRepository(TestEmployee::class)
            ->findAll();

        $this->assertCount(1, $employees);
        $this->assertSame(99, $employees[0]->getOrganization()->getId());
    }

    public function testFilterLogsDebugInformation(): void
    {
        // Mock logger to capture debug messages
        $mockLogger = $this->createMock(LoggerInterface::class);

        // Set tenant context
        $tenant1 = new TestTenant(1, 'tenant-1');
        $this->tenantContext->setTenant($tenant1);

        // Enable filter and inject mock logger
        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled('tenant')) {
            $filters->enable('tenant');
        }

        $tenantFilter = $filters->getFilter('tenant');
        $tenantFilter->setParameter('tenant_id', $tenant1->getId());

        if ($tenantFilter instanceof TenantDoctrineFilter) {
            $tenantFilter->setLogger($mockLogger);
        }

        // Expect debug log when filter is applied
        $mockLogger->expects($this->atLeastOnce())
            ->method('debug')
            ->with('Applied tenant filter constraint', $this->isType('array'));

        // Execute query to trigger filter
        $this->entityManager->getRepository(TestProduct::class)->findAll();
    }

    private function createTestSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    private function dropTestSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
    }

    private function setupTestData(): void
    {
        // Create tenants
        $tenant1 = new TestTenant(1, 'tenant-1');
        $tenant2 = new TestTenant(2, 'tenant-2');
        $this->entityManager->persist($tenant1);
        $this->entityManager->persist($tenant2);

        // Create products for tenant 1
        $product1 = new TestProduct();
        $product1->setName('Product 1');
        $product1->setPrice(25.99);
        $product1->setActive(true);
        $product1->setTenant($tenant1);
        $this->entityManager->persist($product1);

        $product2 = new TestProduct();
        $product2->setName('Product 2');
        $product2->setPrice(75.50);
        $product2->setActive(false);
        $product2->setTenant($tenant1);
        $this->entityManager->persist($product2);

        // Create products for tenant 2
        $product3 = new TestProduct();
        $product3->setName('Product 3');
        $product3->setPrice(45.00);
        $product3->setActive(true);
        $product3->setTenant($tenant2);
        $this->entityManager->persist($product3);

        // Create orders
        $order1 = new TestOrder();
        $order1->setOrderNumber('ORD-001');
        $order1->setTotal(125.99);
        $order1->setProduct($product1);
        $order1->setTenant($tenant1);
        $this->entityManager->persist($order1);

        $order2 = new TestOrder();
        $order2->setOrderNumber('ORD-002');
        $order2->setTotal(45.00);
        $order2->setProduct($product3);
        $order2->setTenant($tenant2);
        $this->entityManager->persist($order2);

        // Create non-tenant entities
        for ($i = 1; $i <= 3; ++$i) {
            $nonTenantEntity = new NonTenantEntity();
            $nonTenantEntity->setName("Entity $i");
            $this->entityManager->persist($nonTenantEntity);
        }

        $this->entityManager->flush();
    }

    private function enableTenantFilter(TenantInterface $tenant): void
    {
        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled('tenant')) {
            $filters->enable('tenant');
        }

        $tenantFilter = $filters->getFilter('tenant');
        $tenantFilter->setParameter('tenant_id', $tenant->getId());

        if ($tenantFilter instanceof TenantDoctrineFilter) {
            $tenantFilter->setLogger($this->logger);
        }
    }
}

// Test entities
#[ORM\Entity]
#[ORM\Table(name: 'test_tenants')]
class TestTenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    public function __construct(int $id, string $slug)
    {
        $this->id = $id;
        $this->slug = $slug;
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        return null;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'test_uuid_tenants')]
class TestUuidTenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    public function __construct(string $id, string $slug)
    {
        $this->id = $id;
        $this->slug = $slug;
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        return null;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'test_products')]
class TestProduct implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $price = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = false;

    #[ORM\ManyToOne(targetEntity: TestTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: TestOrder::class)]
    private iterable $orders = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getOrders(): iterable
    {
        return $this->orders;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'test_uuid_products')]
class TestUuidProduct implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $price = null;

    #[ORM\ManyToOne(targetEntity: TestUuidTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'test_orders')]
class TestOrder implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $orderNumber = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $total = null;

    #[ORM\ManyToOne(targetEntity: TestProduct::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    private ?TestProduct $product = null;

    #[ORM\ManyToOne(targetEntity: TestTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getTotal(): ?float
    {
        return $this->total;
    }

    public function setTotal(float $total): void
    {
        $this->total = $total;
    }

    public function getProduct(): ?TestProduct
    {
        return $this->product;
    }

    public function setProduct(?TestProduct $product): void
    {
        $this->product = $product;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'test_employees')]
#[AsTenantAware(tenantField: 'organization')]
class TestEmployee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: TestTenant::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $organization = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getOrganization(): ?TenantInterface
    {
        return $this->organization;
    }

    public function setOrganization(TenantInterface $organization): void
    {
        $this->organization = $organization;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'non_tenant_entities')]
class NonTenantEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
