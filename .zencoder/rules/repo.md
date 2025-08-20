---
description: Repository Information Overview
alwaysApply: true
---

# Zhortein Multi-Tenant Bundle Information

## Summary
A comprehensive Symfony 7+ bundle for building multi-tenant applications with PostgreSQL 16 support, featuring multiple resolution strategies, tenant-aware services, and automatic database filtering.

## Structure
- **src/**: Core bundle code organized by component
  - **Attribute/**: Custom attributes for tenant-aware entities
  - **Command/**: Console commands for tenant management
  - **Context/**: Tenant context management
  - **Database/**: Database session configuration
  - **DependencyInjection/**: Symfony DI configuration
  - **Doctrine/**: Doctrine integration (filters, listeners)
  - **Entity/**: Core entity interfaces and implementations
  - **Event/**: Event classes for tenant lifecycle
  - **EventListener/**: Event listeners for automatic tenant resolution
  - **EventSubscriber/**: Event subscribers for advanced workflows
  - **Exception/**: Custom exceptions for tenant operations
  - **Helper/**: Utility classes and helpers
  - **Mailer/**: Tenant-aware mailer integration
  - **Manager/**: High-level service managers
  - **Messenger/**: Tenant-aware messenger integration
  - **Middleware/**: HTTP middleware for tenant resolution
  - **Registry/**: Tenant registry implementations
  - **Repository/**: Doctrine repositories for tenant entities
  - **Resolver/**: Tenant resolution strategies
  - **Storage/**: Storage abstraction for tenant data
  - **Decorator/**: Service decorators for tenant-aware functionality
- **tests/**: Test suite with unit, integration, and functional tests
- **docs/**: Comprehensive documentation and examples
- **config/**: Bundle configuration files
- **examples/**: Example implementations and use cases
- **assets/**: Frontend assets when needed

## Language & Runtime
**Language**: PHP
**Version**: >= 8.3
**Framework**: Symfony >= 7.0
**Database**: PostgreSQL 16 (via Doctrine ORM)
**Build System**: Composer
**Package Manager**: Composer
**Frontend**: Supports stimulus/turbo/alpinejs/tailwind 4/bootstrap 5 when needed

## Dependencies
**Main Dependencies**:
- doctrine/doctrine-bundle: ^2.7
- doctrine/doctrine-migrations-bundle: ^3.0
- doctrine/annotations: ^2.0
- monolog/monolog: *
- symfony/cache: ^7.0
- symfony/config: ^7.0
- symfony/contracts: ^3.0
- symfony/dependency-injection: ^7.0
- symfony/filesystem: ^7.0
- symfony/http-kernel: ^7.0
- symfony/orm-pack: ^2.0

**Optional Dependencies**:
- symfony/mailer: For tenant-aware mail sending
- symfony/messenger: For tenant-aware async message dispatching
- symfony/twig-bundle: For templated tenant-aware emails

**Development Dependencies**:
- phpstan/phpstan: ^2.1 (max level)
- phpstan/phpstan-doctrine: ^2.0
- phpstan/phpstan-symfony: ^2.0
- phpunit/phpunit: ^12.2.5
- phpunit/php-code-coverage: ^12.3.1
- friendsofphp/php-cs-fixer: ^v3.75.0
- symfony/phpunit-bridge: ^7.3
- symfony/test-pack: ^1.0
- roave/security-advisories: dev-latest

## Build & Installation
```bash
# Install via Composer
composer require zhortein/multi-tenant-bundle

# Enable the bundle in config/bundles.php
# Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle::class => ['all' => true]

# Create tenant entity implementing TenantInterface
# Configure in config/packages/zhortein_multi_tenant.yaml
```

## Docker
**Docker Setup**: Docker-based development environment
**PHP Image**: php:8.3-cli
**Commands**:
```bash
# Run in Docker container
make installdeps  # Install dependencies
make test         # Run tests
make phpstan      # Run static analysis
make dev-check    # Run all development checks
make ci-check     # Run CI checks
```

## Testing
**Framework**: PHPUnit 12
**Test Location**: tests/ directory
**Structure**:
- Unit tests: tests/Unit/
- Integration tests: tests/Integration/
- Functional tests: tests/Functional/
- Fixtures: tests/Fixtures/
**Run Command**:
```bash
make test          # All tests
make test-unit     # Unit tests only
make test-integration # Integration tests only
make test-coverage # With coverage report
```

## Core Components
- **Tenant Context**: Thread-safe tenant state management
- **Tenant Resolution**: Multiple strategies (subdomain, path, header, DNS, hybrid, chain)
- **Database Integration**: Automatic query filtering with Doctrine
- **Database Strategies**: Shared database with filtering or separate databases per tenant
- **Tenant Registry**: Database and in-memory implementations
- **Settings Management**: Tenant-specific configuration with caching
- **Service Integrations**: Mailer, Messenger, and file storage
- **Console Commands**: Tenant management, migrations, and fixtures
- **PostgreSQL RLS**: Row-Level Security integration for database-level tenant isolation
- **Messenger Integration**: Tenant context propagation in async messages

## Code Quality
- PHPStan at maximum level (Level 9)
- PHP-CS-Fixer with Symfony rules
- Strict typing throughout
- Comprehensive test suite (71+ tests with 139+ assertions)
- Interface-based architecture for extensibility
- Follows Symfony best practices and PSR standards
- DRY and SOLID principles
- Documentation in English
- DTO pattern usage instead of arrays

## Recent Additions
- Enhanced Console Commands System with tenant context management
- Resolver Chain System for configurable multi-strategy tenant resolution
- Messenger Tenant Propagation for async message handling
- PostgreSQL Row-Level Security (RLS) Integration for database-level isolation
- Enhanced Tenant Registry with improved lookup methods
- Tenant impersonation capabilities with security restrictions