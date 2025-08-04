# Zhortein Multi-Tenant Bundle Documentation

Welcome to the comprehensive documentation for the Zhortein Multi-Tenant Bundle, a powerful Symfony 7+ solution for building multi-tenant applications with PostgreSQL 16 support.

## Table of Contents

### Getting Started
- [Installation](installation.md) - Install and enable the bundle
- [Configuration](configuration.md) - Configure the bundle for your needs
- [Tenant Entity](tenant-entity.md) - Create your tenant entity
- [Doctrine Filter](doctrine-filter.md) - Set up automatic tenant filtering

### Core Features
- [Tenant Resolvers](resolvers.md) - Path, subdomain, and custom resolution strategies
- [Tenant Settings](tenant_settings.md) - Manage tenant-specific configuration
- [Database Strategies](tenant_database_info.md) - Shared vs separate database approaches
- [File Storage](storage.md) - Tenant-aware file storage abstraction

### Usage Guide
- [Basic Usage](usage.md) - Common usage patterns and examples
- [Console Commands](commands.md) - Management commands for tenants
- [Testing](testing.md) - Testing multi-tenant applications

### Examples
- [Basic Usage Examples](examples/basic-usage.md) - Practical code examples
- [Configuration Examples](configuration-examples.md) - Various configuration scenarios

## Overview

The Zhortein Multi-Tenant Bundle provides a comprehensive, production-ready solution for building multi-tenant applications with Symfony 7+. It follows Symfony best practices and includes extensive testing and documentation.

### Key Features

- **🏢 Multiple Resolution Strategies**: Path-based, subdomain-based, and custom resolvers
- **🗄️ Database Strategies**: Shared database with filtering or separate databases per tenant
- **⚡ Performance Optimized**: Built-in caching for tenant settings and configurations
- **🔧 Doctrine Integration**: Automatic tenant filtering with Doctrine ORM
- **📧 Service Integrations**: Tenant-aware mailer, messenger, and file storage
- **🎯 Event-Driven Architecture**: Automatic tenant context resolution via event listeners
- **🛠️ Console Commands**: Management commands for tenant operations
- **🧪 Comprehensive Testing**: Full test suite with PHPUnit 12 and PHPStan level max
- **📚 Complete Documentation**: Extensive documentation with examples

### Technical Requirements

- **PHP**: >= 8.3
- **Symfony**: >= 7.0
- **Database**: PostgreSQL 16 (via Doctrine ORM)
- **Extensions**: `ext-json`, `ext-pdo`

### Architecture Highlights

- **Event-driven tenant resolution**: Automatic tenant detection from HTTP requests
- **Compiler passes**: Automatic service configuration and optimization
- **Interface-based design**: Extensible architecture for custom implementations
- **Caching layer**: Performance optimization with configurable cache pools
- **Security-first**: Built-in tenant isolation and data protection

## Quick Start Example

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class DashboardController extends AbstractController
{
    public function index(
        TenantContextInterface $tenantContext,
        TenantSettingsManager $settingsManager
    ): Response {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw $this->createNotFoundException('No tenant found');
        }
        
        // Get tenant-specific settings
        $theme = $settingsManager->get('theme', 'default');
        $companyName = $settingsManager->get('company_name', $tenant->getName());
        
        // All database queries are automatically filtered by tenant
        $articles = $this->entityManager
            ->getRepository(Article::class)
            ->findAll(); // Only returns current tenant's articles
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenant,
            'theme' => $theme,
            'companyName' => $companyName,
            'articles' => $articles,
        ]);
    }
}
```

## Getting Help

- **Documentation**: Browse the complete documentation in this folder
- **Issues**: [GitHub Issues](https://github.com/zhortein/multi-tenant-bundle/issues)
- **Discussions**: [GitHub Discussions](https://github.com/zhortein/multi-tenant-bundle/discussions)
- **Author**: [David Renard](https://www.david-renard.fr)

## Contributing

We welcome contributions! Please see our [contributing guidelines](../CONTRIBUTING.md) and check out the [testing documentation](testing.md) to get started.

## License

This bundle is released under the MIT License. See the [LICENSE](../LICENSE) file for details.