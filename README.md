# Zhortein Multi-Tenant Bundle

A comprehensive Symfony 7+ bundle for building multi-tenant applications with PostgreSQL 16 support.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-blue.svg)](https://php.net/)
[![Symfony Version](https://img.shields.io/badge/symfony-%3E%3D7.0-green.svg)](https://symfony.com/)
[![PostgreSQL Version](https://img.shields.io/badge/postgresql-16-blue.svg)](https://www.postgresql.org/)

## Features

- 🏢 **Multiple Tenant Resolution Strategies**: Subdomain, path-based, or custom resolvers
- 🗄️ **Database Strategies**: Shared database with filtering or separate databases per tenant
- ⚡ **Performance Optimized**: Built-in caching for tenant settings and configurations
- 🔧 **Doctrine Integration**: Automatic tenant filtering with Doctrine ORM
- 📧 **Tenant-Aware Services**: Mailer and Messenger integration
- 🎯 **Event-Driven**: Automatic tenant context resolution via event listeners
- 🛠️ **Console Commands**: Management commands for tenant operations
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
    resolver: 'subdomain'  # or 'path' or 'custom'
    
    subdomain:
        base_domain: 'example.com'
        excluded_subdomains: ['www', 'api', 'admin']
    
    database:
        strategy: 'shared'  # or 'separate'
        enable_filter: true
    
    cache:
        pool: 'cache.app'
        ttl: 3600
```

### 3. Create Tenant-Aware Entities

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
class Article implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private TenantInterface $tenant;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    // Implement TenantOwnedEntityInterface methods...
    
    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    // ... other methods
}
```

### 4. Register Doctrine Filter

Add to your `config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        filters:
            tenant:
                class: Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
                enabled: true
```

## Configuration Reference

```yaml
zhortein_multi_tenant:
    # Tenant entity class (required)
    tenant_entity: 'App\Entity\Tenant'
    
    # Tenant resolution strategy
    resolver: 'path'  # 'path', 'subdomain', or 'custom'
    
    # Default tenant slug (optional)
    default_tenant: null
    
    # Require tenant for all requests
    require_tenant: false
    
    # Subdomain resolver configuration
    subdomain:
        base_domain: 'localhost'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp']
    
    # Database configuration
    database:
        strategy: 'shared'  # 'shared' or 'separate'
        enable_filter: true
    
    # Cache configuration
    cache:
        pool: 'cache.app'
        ttl: 3600
    
    # Service integrations
    mailer:
        enabled: true
    messenger:
        enabled: true
    
    # Event listeners
    listeners:
        request_listener: true
        doctrine_filter_listener: true
```

## Usage Examples

### Accessing Current Tenant

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
        
        if (!$tenant) {
            throw $this->createNotFoundException('No tenant found');
        }
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenant,
        ]);
    }
}
```

### Managing Tenant Settings

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

    public function getThemeColor(): string
    {
        return $this->settingsManager->get('theme_color', '#007bff');
    }

    public function updateThemeColor(string $color): void
    {
        $this->settingsManager->set('theme_color', $color);
    }
}
```

### Custom Tenant Resolver

```php
<?php

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class HeaderTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $tenantSlug = $request->headers->get('X-Tenant-Slug');
        
        if (!$tenantSlug) {
            return null;
        }

        try {
            return $this->tenantRegistry->getBySlug($tenantSlug);
        } catch (\Exception) {
            return null;
        }
    }
}
```

Register your custom resolver:

```yaml
# config/services.yaml
services:
    App\Resolver\HeaderTenantResolver:
        tags:
            - { name: 'zhortein.tenant_resolver' }

# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'custom'
```

## Console Commands

### List All Tenants

```bash
php bin/console tenant:list
```

### Create a New Tenant

```bash
php bin/console tenant:create
```

### Clear Tenant Settings Cache

```bash
# Clear cache for specific tenant
php bin/console tenant:settings:clear-cache tenant-slug

# Clear cache for all tenants
php bin/console tenant:settings:clear-cache --all
```

## Testing

The bundle includes a comprehensive test suite:

```bash
# Run all tests
make test

# Run specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration
vendor/bin/phpunit tests/Functional
```

## Static Analysis

Run PHPStan at maximum level:

```bash
make phpstan
```

## Code Style

Fix code style with PHP-CS-Fixer:

```bash
make csfixer
```

## Architecture

### Core Components

- **TenantContext**: Manages the current tenant state
- **TenantRegistry**: Provides access to tenant entities
- **TenantResolver**: Resolves tenant from HTTP requests
- **TenantSettingsManager**: Manages tenant-specific settings
- **TenantDoctrineFilter**: Automatically filters database queries

### Event Flow

1. **Request Event**: `TenantRequestListener` resolves tenant from request
2. **Doctrine Filter**: `TenantDoctrineFilterListener` configures database filtering
3. **Application Logic**: Controllers and services access tenant context
4. **Response**: Tenant-specific data is returned

## Performance Considerations

- **Caching**: Tenant settings are cached to reduce database queries
- **Database Filtering**: Automatic query filtering at the database level
- **Lazy Loading**: Tenant context is resolved only when needed
- **Connection Pooling**: Support for separate database connections per tenant

## Security

- **Tenant Isolation**: Automatic data isolation between tenants
- **Input Validation**: All tenant identifiers are validated
- **Access Control**: Built-in protection against tenant data leakage

## Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for your changes
4. Ensure all tests pass and code meets quality standards
5. Submit a pull request

## License

This bundle is released under the MIT License. See the [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: [Full documentation](docs/)
- **Issues**: [GitHub Issues](https://github.com/zhortein/multi-tenant-bundle/issues)
- **Discussions**: [GitHub Discussions](https://github.com/zhortein/multi-tenant-bundle/discussions)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes and upgrade instructions.
