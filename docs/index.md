# Zhortein Multi-Tenant Bundle Documentation

Welcome to the comprehensive documentation for the Zhortein Multi-Tenant Bundle, a powerful Symfony 7.4 LTS and Symfony 8.x solution for building multi-tenant applications with PostgreSQL 16 support.

> 📖 **Quick Start**: New to the bundle? Check out the [main README](../README.md) for installation and quick start guide.

## Navigation

- [🏠 Main README](../README.md) - Installation, quick start, and overview
- [📋 Table of Contents](#table-of-contents) - Complete documentation index
- [🚀 Getting Started](#getting-started) - Step-by-step setup guides
- [🏗️ Core Concepts](#core-concepts) - Understanding multi-tenancy concepts
- [🔧 Service Integration](#service-integration) - Integrating with Symfony services
- [🗄️ Database Management](#database-management) - Database strategies and management
- [🛠️ Development & Testing](#development--testing) - Development tools and testing
- [📖 Examples](#examples) - Practical code examples

## Table of Contents

### Getting Started
- [Installation](installation.md) - Install and enable the bundle
- [Dependency classification](dependencies.md) - Required runtime components and optional integrations
- [Configuration](configuration.md) - Complete configuration reference
- [Database Strategies](database-strategies.md) - Shared DB vs Multi-DB approaches
- [Project Overview](project-overview.md) - Architecture and demonstrated project status
- [Release Process](release-process.md) - SemVer, candidate validation, and publication authorization

### Core Concepts
- [Tenant Context](tenant-context.md) - How tenant resolution and access works
- [Tenant Resolution](tenant-resolution.md) - Subdomain, path, header, and custom resolvers
- [Resolver Chain](resolver-chain.md) - Configurable multi-strategy resolution with fallbacks
- [DNS TXT Resolver](dns-txt-resolver.md) - DNS-based tenant resolution with TXT records
- [Domain Resolvers](domain-resolvers.md) - Domain-based and hybrid resolvers
- [Doctrine Tenant Filter](doctrine-tenant-filter.md) - Automatic database filtering
- [Tenant Settings](tenant-settings.md) - Configuration system with fallback rules

### Service Integration
- [Decorators](decorators.md) - Tenant-aware decorators for caching, logging, and storage
- [Mailer](mailer.md) - Tenant-aware email configuration and sending
- [Messenger](messenger.md) - Tenant-aware message queues and processing
- [Storage](storage.md) - Fail-closed tenant-specific file storage
- [Security Contract Migration](migration-security-contracts.md) - Breaking storage, cache, mailer, and observability migration
- [Observability](observability.md) - Monitoring, metrics, and logging for tenant operations

### Database Management
- [Migrations](migrations.md) - Running migrations for each tenant
- [Fixtures](fixtures.md) - Creating and loading fixtures per tenant

### Development & Testing
- [CLI Commands](cli.md) - Console commands with examples
- [Testing & Test Kit](testing.md) - Comprehensive testing utilities and strategies for multi-tenant apps
- [Test Kit Usage Examples](examples/test-kit-usage.md) - Detailed Test Kit examples and best practices
- [FAQ](faq.md) - Frequently asked questions

### Examples
- [Basic Usage Examples](examples/basic-usage.md) - Practical code examples
- [Resolver Chain Usage](examples/resolver-chain-usage.md) - Multi-strategy resolution examples
- [DNS TXT Resolver Usage](examples/dns-txt-resolver-usage.md) - DNS-based resolution examples
- [Domain Resolver Usage](examples/domain-resolver-usage.md) - Domain and hybrid resolver examples
- [Mailer Usage Examples](examples/mailer-usage.md) - Email configuration examples
- [Messenger Usage Examples](examples/messenger-usage.md) - Message queue examples
- [Storage Usage Examples](examples/storage-usage.md) - File storage examples
- [Database Usage Examples](examples/database-usage.md) - Entity and repository examples
- [Observability Usage Examples](examples/observability-usage.md) - Monitoring and metrics examples
- [Issue 12 Security Contract Audit](audit-issue-12-security-contracts.md) - Previous false positives and effective integration coverage

## Overview

The Zhortein Multi-Tenant Bundle provides a pre-1.0 implementation under active verification for building multi-tenant applications with Symfony 7.4 LTS and Symfony 8.x. It follows Symfony best practices and includes extensive testing and documentation.

### Key Features

- **🏢 Multiple Resolution Strategies**: Path-based, subdomain-based, header-based, query-based, domain-based, DNS TXT, hybrid, and custom resolvers
- **🔗 Resolver Chain**: Configurable multi-strategy resolution with strict mode, fallbacks, and comprehensive diagnostics
- **🗄️ Database Strategies**: Shared database with filtering or separate databases per tenant
- **⚡ Performance Optimized**: Built-in caching for tenant settings and configurations
- **🔧 Doctrine Integration**: Automatic tenant filtering with Doctrine ORM
- **📧 Service Integrations**: Tenant-aware decorators, mailer, messenger, and file storage
- **🎯 Event-Driven Architecture**: Automatic tenant context resolution via event listeners
- **🛠️ Enhanced Console Commands**: Comprehensive tenant-aware CLI with global `--tenant` option, environment variable support, and admin impersonation
- **🧪 Comprehensive Test Kit**: First-class testing utilities with RLS isolation verification, tenant context management, and defense-in-depth testing
- **🔒 RLS Integration**: PostgreSQL Row-Level Security for bulletproof tenant isolation
- **📊 Observability & Monitoring**: Built-in events, metrics collection, and structured logging for production monitoring
- **📚 Complete Documentation**: Extensive documentation with examples

### Technical Requirements

- **PHP**: >= 8.3
- **Symfony**: >= 7.4 | 8.0
- **Tested matrix**: See the [compatibility policy](compatibility.md)
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

## Public Test Kit

The optional public API contains only `TenantContextScope`,
`TenantKernelTestCase`, and `TenantWebTestCase`. It manages explicit tenant
context safely but does not replace application authorization, repository
criteria, resolver tests, or effective PostgreSQL RLS tests.

```shell
make test
make test-with-postgres
```

See [Testing](testing.md) and the [executable consumer examples](examples/test-kit-usage.md).

## Getting Help

- **Documentation**: Browse the complete documentation in this folder
- **Issues**: [GitHub Issues](https://github.com/zhortein/multi-tenant-bundle/issues)
- **Discussions**: [GitHub Discussions](https://github.com/zhortein/multi-tenant-bundle/discussions)
- **Author**: [David Renard](https://www.david-renard.fr)

## Contributing

We welcome contributions! Please see our [contributing guidelines](../CONTRIBUTING.md) and check out the [testing documentation](testing.md) to get started.

## License

This bundle is released under the MIT License. See the [LICENSE](../LICENSE) file for details.