# Zhortein Multi-Tenant Bundle

A comprehensive Symfony 7+ bundle for building multi-tenant applications with PostgreSQL 16 support.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-blue.svg)](https://php.net/)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D7.0-green.svg)](https://symfony.com/)
[![PostgreSQL Version](https://img.shields.io/badge/postgresql-16-blue.svg)](https://www.postgresql.org/)

## Features

- 🏢 **Multiple Tenant Resolution Strategies**: Subdomain, path-based, header-based, or custom resolvers
- 🗄️ **Database Strategies**: Shared database with filtering or separate databases per tenant
- ⚡ **Performance Optimized**: Built-in caching for tenant settings and configurations
- 🔧 **Doctrine Integration**: Automatic tenant filtering with Doctrine ORM
- 📧 **Tenant-Aware Services**: Mailer, Messenger, and file storage integration
- 🎯 **Event-Driven**: Database switching events and automatic tenant context resolution
- 🛠️ **Advanced Commands**: Schema management, migrations, and fixtures for tenants
- 🧪 **Fully Tested**: Comprehensive test suite with PHPUnit 12
- 📊 **PHPStan Level Max**: Static analysis at maximum level

## Installation

Install the bundle via Composer:

```bash
composer require zhortein/multi-tenant-bundle
```

Enable the bundle in your `config/bundles.php`:

```php
<?php

return [
    // ...
    Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle::class => ['all' => true],
];
```

## Quick Start

### 1. Create Your Tenant Entity

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    // Implement TenantInterface methods...
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    // ... other methods
}
```

### 2. Configure the Bundle

Create `config/packages/zhortein_multi_tenant.yaml`:

```yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver:
        type: 'subdomain'
        options:
            base_domain: 'example.com'
    database:
        strategy: 'shared_db'
        enable_filter: true
```

### 3. Create Tenant-Aware Entities

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\TenantAwareEntityTrait;

#[ORM\Entity]
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

    // ... other properties and methods
}
```

### 4. Use in Controllers

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DashboardController extends AbstractController
{
    public function index(TenantContextInterface $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        
        // All database queries are automatically filtered by tenant
        $products = $this->entityManager
            ->getRepository(Product::class)
            ->findAll(); // Only returns current tenant's products
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenant,
            'products' => $products,
        ]);
    }
}
```

## 📚 Documentation

### 🚀 Getting Started
- [Installation & Setup](docs/installation.md) - Complete installation guide
- [Configuration Reference](docs/configuration.md) - All configuration options
- [Database Strategies](docs/database-strategies.md) - Shared DB vs Multi-DB

### 🏗️ Core Concepts
- [Tenant Context](docs/tenant-context.md) - Tenant resolution and access
- [Tenant Resolution](docs/tenant-resolution.md) - Resolution strategies
- [Doctrine Tenant Filter](docs/doctrine-tenant-filter.md) - Automatic filtering
- [Tenant Settings](docs/tenant-settings.md) - Configuration system

### 🔧 Service Integration
- [Mailer](docs/mailer.md) - Tenant-aware email
- [Messenger](docs/messenger.md) - Tenant-aware queues
- [Storage](docs/storage.md) - File storage isolation

### 🗄️ Database Management
- [Migrations](docs/migrations.md) - Database migrations
- [Fixtures](docs/fixtures.md) - Test data loading

### 🛠️ Development Tools
- [CLI Commands](docs/cli.md) - Console commands
- [Testing](docs/testing.md) - Testing strategies
- [FAQ](docs/faq.md) - Common questions

### 📖 Examples
- [Basic Usage](docs/examples/basic-usage.md) - Code examples
- [Service Integration](docs/examples/) - Practical implementations

## Testing

Run the test suite:

```bash
# Run all tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

## Static Analysis

```bash
# PHPStan at maximum level
vendor/bin/phpstan analyse
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for your changes
4. Ensure all tests pass and code meets quality standards
5. Submit a pull request

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

## License

This bundle is released under the MIT License. See the [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: [Complete documentation](docs/)
- **Issues**: [GitHub Issues](https://github.com/zhortein/multi-tenant-bundle/issues)
- **Discussions**: [GitHub Discussions](https://github.com/zhortein/multi-tenant-bundle/discussions)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and upgrade instructions.