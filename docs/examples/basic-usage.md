# Basic Usage Examples

This document provides practical examples of using the Zhortein Multi-Tenant Bundle in common scenarios.

## Controller Examples

### Basic Tenant Access

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard')]
    public function index(TenantContextInterface $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw $this->createNotFoundException('No tenant found');
        }
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenant,
            'tenant_name' => $tenant->getName(),
            'tenant_slug' => $tenant->getSlug(),
        ]);
    }
}
```

### Working with Tenant-Aware Entities

```php
<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    #[Route('/products', name: 'product_list')]
    public function list(ProductRepository $productRepository): Response
    {
        // Automatically filtered by current tenant
        $products = $productRepository->findAll();
        
        return $this->render('product/list.html.twig', [
            'products' => $products,
        ]);
    }
    
    #[Route('/products/new', name: 'product_create', methods: ['POST'])]
    public function create(EntityManagerInterface $entityManager): Response
    {
        $product = new Product();
        $product->setName('New Product');
        $product->setPrice('29.99');
        // Tenant is automatically assigned via entity listener
        
        $entityManager->persist($product);
        $entityManager->flush();
        
        return $this->json(['id' => $product->getId()]);
    }
}
```

## Service Examples

### Tenant-Aware Service

```php
<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
    ) {}

    public function getProducts(): array
    {
        // Automatically filtered by current tenant
        return $this->productRepository->findAll();
    }

    public function createProduct(array $data): Product
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        $product = new Product();
        $product->setName($data['name']);
        $product->setPrice($data['price']);
        // Tenant automatically assigned via entity listener
        
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        
        return $product;
    }
}
```

### Using Tenant Settings

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantConfigService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
        private TenantContextInterface $tenantContext,
    ) {}

    public function getThemeSettings(): array
    {
        return [
            'theme' => $this->settingsManager->get('theme', 'default'),
            'primary_color' => $this->settingsManager->get('primary_color', '#007bff'),
            'logo_url' => $this->settingsManager->get('logo_url', '/default-logo.png'),
            'company_name' => $this->settingsManager->get('company_name', $this->tenantContext->getTenant()?->getName()),
        ];
    }

    public function updateThemeSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->settingsManager->set($key, $value);
        }
    }
}
```

## Entity Examples

### Shared Database Entity

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\TenantAwareEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[AsTenantAware]
class Product
{
    use TenantAwareEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    // Getters and setters...
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
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
}
```

### Multi-Database Entity

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\MultiDbTenantAwareTrait;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[AsTenantAware(requireTenantId: false)] // No tenant_id field needed
class Order
{
    use MultiDbTenantAwareTrait; // Provides tenant context without DB field

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $orderNumber;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total;

    // No tenant relationship needed - each tenant has its own database

    // Getters and setters...
}
```

## Twig Examples

### Accessing Tenant in Templates

```twig
{# templates/base.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{{ tenant_context.tenant ? tenant_context.tenant.name : 'Multi-Tenant App' }}</title>
</head>
<body>
    {% if tenant_context.tenant %}
        <header>
            <h1>{{ tenant_context.tenant.name }}</h1>
            <p>Tenant: {{ tenant_context.tenant.slug }}</p>
        </header>
    {% endif %}
    
    {% block body %}{% endblock %}
</body>
</html>
```

### Using Tenant Settings in Templates

```twig
{# templates/dashboard/index.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <div class="dashboard" style="--primary-color: {{ tenant_setting('primary_color', '#007bff') }}">
        <h2>Welcome to {{ tenant_setting('company_name', tenant_context.tenant.name) }}</h2>
        
        {% if tenant_setting('show_stats', true) %}
            <div class="stats">
                <p>Total Products: {{ products|length }}</p>
            </div>
        {% endif %}
    </div>
{% endblock %}
```

## Testing Examples

### Unit Test with Tenant Context

```php
<?php

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use App\Service\ProductService;
use App\Repository\ProductRepository;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Test\TenantStub;

class ProductServiceTest extends TestCase
{
    public function testGetProductsWithTenant(): void
    {
        $tenant = new TenantStub('test-tenant');
        $expectedProducts = [/* mock products */];

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($expectedProducts);

        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $service = new ProductService($productRepository, $tenantContext);
        $result = $service->getProducts();

        $this->assertSame($expectedProducts, $result);
    }
}
```

These examples demonstrate the most common patterns for working with the multi-tenant bundle in real applications.