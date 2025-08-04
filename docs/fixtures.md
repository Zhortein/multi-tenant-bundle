# Tenant Fixtures

The tenant fixtures system allows you to load test data and seed data for each tenant independently. It integrates with Doctrine Fixtures Bundle to provide tenant-aware data loading capabilities.

## Overview

The fixtures system provides:

- **Tenant-specific fixtures**: Load different data for each tenant
- **Shared fixtures**: Load common data across all tenants
- **Dependency management**: Control fixture loading order
- **Batch operations**: Load fixtures for all tenants at once
- **Environment-aware**: Different fixtures for different environments
- **Data isolation**: Complete separation of fixture data between tenants

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    fixtures:
        enabled: true
        auto_tenant_assignment: true # Automatically assign current tenant to entities
```

### Doctrine Fixtures Configuration

```yaml
# config/packages/doctrine_fixtures.yaml
doctrine_fixtures:
    dir: '%kernel.project_dir%/src/DataFixtures'
```

## Basic Fixtures

### Tenant Fixtures

```php
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Tenant;

class TenantFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tenants = [
            [
                'slug' => 'acme-corp',
                'name' => 'ACME Corporation',
                'domain' => 'acme.example.com',
                'active' => true,
            ],
            [
                'slug' => 'tech-startup',
                'name' => 'Tech Startup Inc',
                'domain' => 'tech.example.com',
                'active' => true,
            ],
            [
                'slug' => 'retail-store',
                'name' => 'Retail Store Ltd',
                'domain' => 'retail.example.com',
                'active' => true,
            ],
        ];

        foreach ($tenants as $index => $tenantData) {
            $tenant = new Tenant();
            $tenant->setSlug($tenantData['slug']);
            $tenant->setName($tenantData['name']);
            $tenant->setDomain($tenantData['domain']);
            $tenant->setActive($tenantData['active']);

            $manager->persist($tenant);
            
            // Add reference for use in other fixtures
            $this->addReference('tenant-' . $index, $tenant);
        }

        $manager->flush();
    }
}
```

### Tenant-Aware Product Fixtures

```php
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Product;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Load products for each tenant
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            
            // Set tenant context for automatic assignment
            $this->tenantContext->setTenant($tenant);

            $products = $this->getProductsForTenant($tenantIndex);

            foreach ($products as $productData) {
                $product = new Product();
                $product->setName($productData['name']);
                $product->setPrice($productData['price']);
                $product->setDescription($productData['description']);
                $product->setStock($productData['stock']);
                $product->setActive($productData['active']);
                
                // Tenant is automatically assigned via entity listener
                // or you can set it manually: $product->setTenant($tenant);

                $manager->persist($product);
            }
        }

        $manager->flush();
        
        // Clear tenant context
        $this->tenantContext->clear();
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
                [
                    'name' => 'ACME Widget Pro',
                    'price' => '29.99',
                    'description' => 'Professional grade widget for enterprise use',
                    'stock' => 100,
                    'active' => true,
                ],
                [
                    'name' => 'ACME Gadget Deluxe',
                    'price' => '49.99',
                    'description' => 'Advanced gadget with premium features',
                    'stock' => 50,
                    'active' => true,
                ],
                [
                    'name' => 'ACME Tool Kit',
                    'price' => '89.99',
                    'description' => 'Complete tool kit for professionals',
                    'stock' => 25,
                    'active' => true,
                ],
            ],
            // Tech Startup products
            [
                [
                    'name' => 'Cloud Solution Alpha',
                    'price' => '99.99',
                    'description' => 'Innovative cloud-based solution',
                    'stock' => 25,
                    'active' => true,
                ],
                [
                    'name' => 'AI Assistant Beta',
                    'price' => '149.99',
                    'description' => 'AI-powered assistant for businesses',
                    'stock' => 10,
                    'active' => true,
                ],
                [
                    'name' => 'Analytics Dashboard',
                    'price' => '199.99',
                    'description' => 'Real-time analytics and reporting',
                    'stock' => 15,
                    'active' => true,
                ],
            ],
            // Retail Store products
            [
                [
                    'name' => 'Everyday Essential',
                    'price' => '19.99',
                    'description' => 'Popular everyday item',
                    'stock' => 200,
                    'active' => true,
                ],
                [
                    'name' => 'Premium Collection',
                    'price' => '39.99',
                    'description' => 'High-quality premium item',
                    'stock' => 75,
                    'active' => true,
                ],
                [
                    'name' => 'Limited Edition',
                    'price' => '59.99',
                    'description' => 'Exclusive limited edition product',
                    'stock' => 30,
                    'active' => true,
                ],
            ],
        ];

        return $productSets[$tenantIndex] ?? [];
    }
}
```

### User Fixtures with Tenant Assignment

```php
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class UserFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Create users for each tenant
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            $this->tenantContext->setTenant($tenant);

            $users = $this->getUsersForTenant($tenantIndex);

            foreach ($users as $userData) {
                $user = new User();
                $user->setEmail($userData['email']);
                $user->setName($userData['name']);
                $user->setRoles($userData['roles']);
                
                // Hash password
                $hashedPassword = $this->passwordHasher->hashPassword($user, $userData['password']);
                $user->setPassword($hashedPassword);
                
                // Tenant is automatically assigned
                $user->setTenant($tenant);

                $manager->persist($user);
                
                // Add reference for other fixtures
                $this->addReference(
                    sprintf('user-%s-%s', $tenant->getSlug(), $userData['role']),
                    $user
                );
            }
        }

        $manager->flush();
        $this->tenantContext->clear();
    }

    public function getDependencies(): array
    {
        return [TenantFixtures::class];
    }

    private function getUsersForTenant(int $tenantIndex): array
    {
        $tenantSlugs = ['acme-corp', 'tech-startup', 'retail-store'];
        $tenantSlug = $tenantSlugs[$tenantIndex];

        return [
            [
                'email' => sprintf('admin@%s.com', str_replace('-', '', $tenantSlug)),
                'name' => 'Admin User',
                'password' => 'admin123',
                'roles' => ['ROLE_ADMIN'],
                'role' => 'admin',
            ],
            [
                'email' => sprintf('manager@%s.com', str_replace('-', '', $tenantSlug)),
                'name' => 'Manager User',
                'password' => 'manager123',
                'roles' => ['ROLE_MANAGER'],
                'role' => 'manager',
            ],
            [
                'email' => sprintf('user@%s.com', str_replace('-', '', $tenantSlug)),
                'name' => 'Regular User',
                'password' => 'user123',
                'roles' => ['ROLE_USER'],
                'role' => 'user',
            ],
        ];
    }
}
```

## Advanced Fixtures

### Order Fixtures with Relationships

```php
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Order;
use App\Entity\OrderItem;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            $this->tenantContext->setTenant($tenant);

            // Get tenant-specific user and products
            $customer = $this->getReference(sprintf('user-%s-user', $tenant->getSlug()));
            
            // Create sample orders
            for ($orderIndex = 0; $orderIndex < 5; $orderIndex++) {
                $order = new Order();
                $order->setCustomer($customer);
                $order->setStatus($this->getRandomStatus());
                $order->setTenant($tenant);

                $total = 0;

                // Add random products to order
                $productCount = random_int(1, 3);
                for ($itemIndex = 0; $itemIndex < $productCount; $itemIndex++) {
                    $product = $this->getRandomProductForTenant($tenantIndex);
                    $quantity = random_int(1, 3);
                    $unitPrice = $product->getPrice();

                    $orderItem = new OrderItem();
                    $orderItem->setOrder($order);
                    $orderItem->setProduct($product);
                    $orderItem->setQuantity($quantity);
                    $orderItem->setUnitPrice($unitPrice);
                    $orderItem->setTenant($tenant);

                    $order->addItem($orderItem);
                    $total += $unitPrice * $quantity;

                    $manager->persist($orderItem);
                }

                $order->setTotal($total);
                $manager->persist($order);
            }
        }

        $manager->flush();
        $this->tenantContext->clear();
    }

    public function getDependencies(): array
    {
        return [
            TenantFixtures::class,
            UserFixtures::class,
            ProductFixtures::class,
        ];
    }

    private function getRandomStatus(): string
    {
        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        return $statuses[array_rand($statuses)];
    }

    private function getRandomProductForTenant(int $tenantIndex): Product
    {
        // This would need to be implemented to get random products
        // for the specific tenant from the database
        throw new \RuntimeException('Not implemented');
    }
}
```

### Environment-Specific Fixtures

```php
<?php

namespace App\DataFixtures\Dev;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Product;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DevProductFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Load development-specific test data
        $tenant = $this->getReference('tenant-0'); // First tenant
        $this->tenantContext->setTenant($tenant);

        // Create test products with debug information
        for ($i = 1; $i <= 50; $i++) {
            $product = new Product();
            $product->setName(sprintf('Test Product %d', $i));
            $product->setPrice(sprintf('%.2f', random_int(1000, 10000) / 100));
            $product->setDescription(sprintf('This is test product %d for development', $i));
            $product->setStock(random_int(0, 100));
            $product->setActive(random_int(0, 1) === 1);
            $product->setTenant($tenant);

            $manager->persist($product);
        }

        $manager->flush();
        $this->tenantContext->clear();
    }

    public static function getGroups(): array
    {
        return ['dev'];
    }
}
```

### Production Fixtures

```php
<?php

namespace App\DataFixtures\Prod;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Category;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProdCategoryFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Load production seed data
        $categories = [
            'Electronics',
            'Clothing',
            'Books',
            'Home & Garden',
            'Sports & Outdoors',
            'Health & Beauty',
            'Automotive',
            'Toys & Games',
        ];

        // Create categories for each tenant
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            $this->tenantContext->setTenant($tenant);

            foreach ($categories as $categoryName) {
                $category = new Category();
                $category->setName($categoryName);
                $category->setSlug(strtolower(str_replace(' & ', '-', str_replace(' ', '-', $categoryName))));
                $category->setTenant($tenant);

                $manager->persist($category);
            }
        }

        $manager->flush();
        $this->tenantContext->clear();
    }

    public static function getGroups(): array
    {
        return ['prod'];
    }
}
```

## Custom Fixture Commands

### Tenant-Specific Fixture Loading

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsCommand(
    name: 'tenant:fixtures:load',
    description: 'Load fixtures for specific tenant or all tenants'
)]
class LoadTenantFixturesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRegistryInterface $tenantRegistry,
        private TenantContextInterface $tenantContext,
        private SymfonyFixturesLoader $fixturesLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Load fixtures for specific tenant')
            ->addOption('group', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Only load fixtures that belong to this group')
            ->addOption('append', null, InputOption::VALUE_NONE, 'Append fixtures instead of purging database')
            ->addOption('purge-exclusions', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'List of tables to exclude from purging');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $tenantSlug = $input->getOption('tenant');
        $groups = $input->getOption('group');
        $append = $input->getOption('append');
        $purgeExclusions = $input->getOption('purge-exclusions');

        try {
            if ($tenantSlug) {
                $tenant = $this->tenantRegistry->getBySlug($tenantSlug);
                $this->loadFixturesForTenant($tenant, $groups, $append, $purgeExclusions, $io);
            } else {
                $this->loadFixturesForAllTenants($groups, $append, $purgeExclusions, $io);
            }

            $io->success('Fixtures loaded successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to load fixtures: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function loadFixturesForTenant(
        TenantInterface $tenant,
        array $groups,
        bool $append,
        array $purgeExclusions,
        SymfonyStyle $io
    ): void {
        $io->section(sprintf('Loading fixtures for tenant: %s', $tenant->getSlug()));

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        try {
            // Load fixtures
            $fixtures = $this->fixturesLoader->getFixtures($groups);
            
            if (empty($fixtures)) {
                $io->note('No fixtures found to load');
                return;
            }

            // Configure purger
            $purger = new ORMPurger($this->entityManager);
            $purger->setPurgeMode(ORMPurger::PURGE_MODE_DELETE);
            
            if (!empty($purgeExclusions)) {
                $purger->setExcludedTables($purgeExclusions);
            }

            // Execute fixtures
            $executor = new ORMExecutor($this->entityManager, $purger);
            $executor->execute($fixtures, $append);

            $io->text(sprintf('Loaded %d fixtures for tenant %s', count($fixtures), $tenant->getSlug()));

        } finally {
            $this->tenantContext->clear();
        }
    }

    private function loadFixturesForAllTenants(
        array $groups,
        bool $append,
        array $purgeExclusions,
        SymfonyStyle $io
    ): void {
        $tenants = $this->tenantRegistry->getAll();
        
        if (empty($tenants)) {
            $io->warning('No tenants found');
            return;
        }

        $io->title('Loading fixtures for all tenants');

        foreach ($tenants as $tenant) {
            $this->loadFixturesForTenant($tenant, $groups, $append, $purgeExclusions, $io);
        }
    }
}
```

## Running Fixtures

### Basic Commands

```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures:load

# Load fixtures for specific tenant
php bin/console tenant:fixtures:load --tenant=acme

# Load fixtures with specific groups
php bin/console tenant:fixtures:load --group=dev --group=test

# Append fixtures without purging
php bin/console tenant:fixtures:load --append

# Exclude specific tables from purging
php bin/console tenant:fixtures:load --purge-exclusions=audit_log --purge-exclusions=system_config
```

### Environment-Specific Loading

```bash
# Load development fixtures
php bin/console tenant:fixtures:load --group=dev --env=dev

# Load production seed data
php bin/console tenant:fixtures:load --group=prod --env=prod

# Load test fixtures
php bin/console tenant:fixtures:load --group=test --env=test
```

## Fixture Factories

### Product Factory

```php
<?php

namespace App\DataFixtures\Factory;

use App\Entity\Product;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class ProductFactory
{
    public static function create(
        string $name,
        string $price,
        TenantInterface $tenant,
        array $options = []
    ): Product {
        $product = new Product();
        $product->setName($name);
        $product->setPrice($price);
        $product->setTenant($tenant);
        
        // Set optional properties
        $product->setDescription($options['description'] ?? null);
        $product->setStock($options['stock'] ?? 0);
        $product->setActive($options['active'] ?? true);
        
        if (isset($options['category'])) {
            $product->setCategory($options['category']);
        }

        return $product;
    }

    public static function createRandom(TenantInterface $tenant): Product
    {
        $names = [
            'Premium Widget', 'Advanced Gadget', 'Professional Tool',
            'Essential Item', 'Deluxe Package', 'Standard Solution'
        ];

        $descriptions = [
            'High-quality product for professional use',
            'Essential item for everyday tasks',
            'Premium solution with advanced features',
            'Reliable and durable construction',
        ];

        return self::create(
            $names[array_rand($names)],
            sprintf('%.2f', random_int(1000, 50000) / 100),
            $tenant,
            [
                'description' => $descriptions[array_rand($descriptions)],
                'stock' => random_int(0, 100),
                'active' => random_int(0, 1) === 1,
            ]
        );
    }
}
```

### Using Factories in Fixtures

```php
<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\DataFixtures\Factory\ProductFactory;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class RandomProductFixtures extends Fixture
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function load(ObjectManager $manager): void
    {
        for ($tenantIndex = 0; $tenantIndex < 3; $tenantIndex++) {
            $tenant = $this->getReference('tenant-' . $tenantIndex);
            $this->tenantContext->setTenant($tenant);

            // Create 20 random products for each tenant
            for ($i = 0; $i < 20; $i++) {
                $product = ProductFactory::createRandom($tenant);
                $manager->persist($product);
            }
        }

        $manager->flush();
        $this->tenantContext->clear();
    }
}
```

## Testing with Fixtures

### Test Case with Fixtures

```php
<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use App\DataFixtures\TenantFixtures;
use App\DataFixtures\ProductFixtures;

class ProductControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Load test fixtures
        $this->loadFixtures([
            TenantFixtures::class,
            ProductFixtures::class,
        ]);
    }

    public function testProductList(): void
    {
        $client = static::createClient();
        
        // Test with tenant context
        $client->request('GET', '/products', [], [], [
            'HTTP_HOST' => 'acme.example.com'
        ]);

        $this->assertResponseIsSuccessful();
        
        // Verify tenant-specific products are shown
        $this->assertSelectorTextContains('h1', 'Products');
        $this->assertSelectorExists('.product-item');
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
}
```

## Best Practices

### 1. Use References

```php
// Good - use references for relationships
$this->addReference('tenant-acme', $tenant);
$tenant = $this->getReference('tenant-acme');

// Bad - hardcode IDs
$tenant = $manager->find(Tenant::class, 1);
```

### 2. Set Tenant Context

```php
// Good - set tenant context for automatic assignment
$this->tenantContext->setTenant($tenant);
$product = new Product(); // Tenant automatically assigned

// Bad - manually set tenant for every entity
$product->setTenant($tenant);
```

### 3. Use Factories for Complex Objects

```php
// Good - use factory for consistent object creation
$product = ProductFactory::createRandom($tenant);

// Bad - duplicate object creation logic
$product = new Product();
$product->setName('Random Product');
// ... lots of repetitive code
```

### 4. Group Fixtures by Environment

```php
// Good - environment-specific fixtures
class DevProductFixtures implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['dev'];
    }
}

// Load with: php bin/console tenant:fixtures:load --group=dev
```

### 5. Handle Dependencies Properly

```php
// Good - declare dependencies
public function getDependencies(): array
{
    return [TenantFixtures::class, UserFixtures::class];
}

// Bad - assume fixtures load in order
```

## Troubleshooting

### Common Issues

1. **Tenant Context Not Set**: Ensure tenant context is set before creating entities
2. **Reference Not Found**: Check fixture dependencies and reference names
3. **Foreign Key Constraints**: Load fixtures in correct dependency order
4. **Memory Issues**: Use batch processing for large datasets

### Debug Commands

```bash
# List available fixtures
php bin/console debug:container --tag=doctrine.fixture.orm

# Check fixture dependencies
php bin/console tenant:fixtures:load --dry-run

# Load specific fixture class
php bin/console tenant:fixtures:load --class=App\\DataFixtures\\ProductFixtures
```