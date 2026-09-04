# Zhortein Multi-Tenant Bundle

A fail-closed Symfony 7.4 LTS and Symfony 8.x bundle for building multi-tenant applications, with PostgreSQL RLS as an optional defense in depth.

RC5 makes tenant state explicitly resettable for persistent kernels and
workers. Every main HTTP request, received Messenger message, reused Console
command, and `TenantExecutionBoundaryInterface` callback starts from `NONE`.
`TenantContext` remains a shared mutable service; it is not recreated for every
request.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-blue.svg)](https://php.net/)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D7.4%20%7C%208.0-green.svg)](https://symfony.com/)
[![PostgreSQL Version](https://img.shields.io/badge/postgresql-%3E%3D16-blue.svg)](https://www.postgresql.org/)

## Features

- 🏢 **Multiple Tenant Resolution Strategies**: Subdomain, path-based, header-based, domain-based, DNS TXT, hybrid, or custom resolvers
- 🗄️ **Database Strategies**: Shared database with filtering or separate databases per tenant
- ⚡ **Performance Optimized**: Built-in caching for tenant settings and configurations
- 🔧 **Doctrine Integration**: Automatic tenant filtering with Doctrine ORM
- 📧 **Tenant-Aware Services**: Mailer with automatic tenant propagation, Messenger with context preservation, and file storage integration
- 🎯 **Event-Driven**: Database switching events and automatic tenant context resolution
- 🛠️ **Advanced Commands**: Schema management, migrations, and fixtures for tenants
- 🧪 **Comprehensive Test Kit**: First-class testing utilities to prove tenant isolation works
- 🔒 **RLS Integration**: PostgreSQL Row-Level Security for defense-in-depth
- 📊 **PHPStan Level Max**: Static analysis at maximum level

## Fail-closed security contract

Tenant-aware Doctrine reads and writes require a valid current tenant and reject invalid mappings, stale filter state, tenant changes, and cross-tenant mutations. Global ORM operations are explicit callbacks through `GlobalDoctrineScopeInterface`; direct filter disabling is outside the supported contract.

Messenger messages implement exactly one of `TenantAwareMessageInterface` or `GlobalMessageInterface`. Tenant-aware messages require consistent tenant metadata at send and receive time, while global messages must never carry a tenant stamp. See the [RC1 to RC2 migration guide](docs/migration-rc1-to-rc2.md).

Messenger transport selection is explicit: `tenant_transport` preserves the historical per-tenant map/default behavior, while `symfony_routing` leaves transport stamps untouched so `framework.messenger.routing` and `#[AsMessage]` work natively. Native mode has no bundle fallback; an unrouted message with a handler may run synchronously. See [Messenger](docs/messenger.md) and the [RC7 to RC8 migration guide](docs/migration-rc7-to-rc8.md).

## Installation

Install the bundle via Composer:

```bash
composer require "zhortein/multi-tenant-bundle:1.0.0-rc.8"
```

The core dependency set and optional Mailer, Twig, Monolog, and PSR-16 integrations are listed in the [dependency classification](docs/dependencies.md).

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
    database:
        strategy: 'shared_db'
        enable_filter: true
        rls:
            enabled: false
    fixtures:
        enabled: false
    mailer:
        enabled: false
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
- [Compatibility Policy](docs/compatibility.md) - Tested PHP, Symfony, and Doctrine combinations
- [Configuration Reference](docs/configuration.md) - All configuration options
- [Database Strategies](docs/database-strategies.md) - Shared DB vs Multi-DB

### 🏗️ Core Concepts
- [Tenant Context](docs/tenant-context.md) - Tenant resolution and access
- [Tenant Resolution](docs/tenant-resolution.md) - Resolution strategies
- [DNS TXT Resolver](docs/dns-txt-resolver.md) - DNS-based tenant resolution
- [Domain Resolvers](docs/domain-resolvers.md) - Domain and hybrid resolvers
- [Doctrine Tenant Filter](docs/doctrine-tenant-filter.md) - Automatic filtering
- [Tenant Settings](docs/tenant-settings.md) - Configuration system

### 🔧 Service Integration
- [Mailer](docs/mailer.md) - Tenant-aware email with templated support
- [Messenger](docs/messenger.md) - Tenant-aware queues with automatic context propagation
- [Storage](docs/storage.md) - Fail-closed file storage isolation
- [Security Contract Migration](docs/migration-security-contracts.md) - Required storage, cache, mailer, and observability migration
- [RC4 to RC5 Migration](docs/migration-rc4-to-rc5.md) - Persistent lifecycle reset and early/late HTTP resolution
- [RC7 to RC8 Migration](docs/migration-rc7-to-rc8.md) - Choose tenant-specific or native Symfony Messenger routing
- [Persistent Process Lifecycle](docs/persistent-lifecycle.md) - Complete state inventory, reset order, and failure behavior

### 🗄️ Database Management
- [Migrations](docs/migrations.md) - Database migrations
- [Fixtures](docs/fixtures.md) - Test data loading

### 🛠️ Development Tools
- [CLI Commands](docs/cli.md) - Console commands
- [Testing](docs/testing.md) - Testing strategies and Test Kit
- [FAQ](docs/faq.md) - Common questions

### 📖 Examples
- [Basic Usage](docs/examples/basic-usage.md) - Code examples
- [Mailer Examples](docs/examples/mailer-usage.md) - Email templates and configuration
- [Messenger Examples](docs/examples/messenger-usage.md) - Message routing and handling
- [Service Integration](docs/examples/) - Practical implementations

## Testing with the Bundle

The optional public Test Kit provides three intentionally small APIs:

- `TenantContextScope` executes a callback under a consumer-defined tenant and
  restores the previous context in all outcomes;
- `TenantKernelTestCase` integrates that scope with Symfony kernel tests;
- `TenantWebTestCase` integrates the same lifecycle with Symfony functional
  tests without selecting a resolver or database strategy.

### Quick Example

```php
<?php

use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Test\TenantKernelTestCase;

final class ProductRepositoryTest extends TenantKernelTestCase
{
    public function testTenantIsolation(): void
    {
        $tenantA = new Tenant("tenant-a");
        $tenantB = new Tenant("tenant-b");

        self::assertSame(
            ["A product"],
            $this->withTenant($tenantA, fn (): array => $this->repository->findForTenant($tenantA)),
        );
        self::assertSame(
            ["B product"],
            $this->withTenant($tenantB, fn (): array => $this->repository->findForTenant($tenantB)),
        );
    }
}
```

### Running Tests

```bash
# Run the complete bundle suite
make test

# Run unit and integration subsets
make test-unit
make test-integration

# Run effective PostgreSQL RLS isolation tests
make test-with-postgres
```

See the [Testing Documentation](docs/testing.md) for installation, lifecycle,
and consumer examples.

## Code Quality

```bash
# PHPStan at maximum level
make phpstan

# PHP-CS-Fixer code style check
make csfixer-check

# Fix code style
make csfixer

# Run all quality checks
make dev-check
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
