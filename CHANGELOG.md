# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-RC1] - 2025-01-13

### Added
- **Core Multi-Tenancy Features**
  - Tenant context management with `TenantContext` and `TenantContextInterface`
  - Multiple tenant resolution strategies: path-based, subdomain-based, header-based, domain-based, DNS TXT, hybrid, and custom resolvers
  - DNS TXT resolver for DNS-based tenant resolution with configurable timeout and caching
  - Domain-based and hybrid resolvers for flexible domain mapping
  - Automatic tenant resolution via `TenantRequestListener`
  - Tenant registry with Doctrine and in-memory implementations

- **Database Integration**
  - Doctrine ORM integration with automatic tenant filtering via `TenantDoctrineFilter`
  - Support for shared database with tenant filtering strategy
  - Support for separate databases per tenant strategy (via `TenantConnectionResolverInterface`)
  - Automatic entity tagging for tenant-aware entities with `AsTenantAware` attribute
  - `TenantOwnedEntityInterface` for entities that belong to tenants

- **Tenant Settings Management**
  - `TenantSettingsManager` with caching support for tenant-specific configuration
  - `TenantSetting` entity for storing key-value tenant settings
  - Cache integration with configurable TTL and cache pools

- **Service Integrations**
  - Tenant-aware mailer configuration with `TenantMailerConfigurator` and `TenantMailerHelper`
  - Tenant-aware messenger configuration with `TenantMessengerConfigurator`
  - File storage abstraction with `TenantFileStorageInterface` and `LocalStorage` implementation
  - Asset uploader helper with `TenantAssetUploader`

- **Console Commands**
  - `tenant:list` - List all tenants
  - `tenant:create` - Create new tenants interactively
  - `tenant:migrate` - Run migrations for specific tenants
  - `tenant:settings:clear-cache` - Clear tenant settings cache

- **Configuration System**
  - Comprehensive bundle configuration with validation
  - Support for multiple resolver types and database strategies
  - Configurable cache settings and service integrations
  - Event listener configuration options

- **Developer Experience**
  - Comprehensive PHPUnit test suite with unit, integration, and functional tests
  - PHPStan level max static analysis compliance
  - PHP-CS-Fixer code style enforcement
  - Symfony 7+ best practices compliance
  - Full English documentation

### Technical Requirements
- PHP >= 8.3
- Symfony >= 7.0
- PostgreSQL 16 support via Doctrine ORM
- Doctrine Bundle >= 2.7
- Doctrine Migrations Bundle >= 3.0

### Architecture
- Event-driven tenant resolution and context management
- Compiler passes for automatic service configuration
- Flexible resolver pattern for custom tenant identification
- Interface-based design for extensibility
- Caching layer for performance optimization

## [Unreleased]

### Planned Features
- UX/Admin bundle integration for tenant management UI
- Enhanced connection factory for database switching
- Tenant user interface and authentication integration
- Advanced file storage adapters (S3, etc.)
- Tenant-specific routing capabilities
