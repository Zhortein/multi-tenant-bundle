# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Resolver Chain System**
  - `ChainTenantResolver` for configurable multi-strategy tenant resolution
  - `QueryTenantResolver` for query parameter-based tenant resolution
  - Configurable resolver order with `resolver_chain.order` setting
  - Strict mode with `resolver_chain.strict` for validation and error handling
  - Header allow-list with `resolver_chain.header_allow_list` for security
  - `TenantResolutionException` and `AmbiguousTenantResolutionException` for error handling
  - `TenantResolutionExceptionListener` for converting exceptions to HTTP 400 responses
  - Comprehensive logging and diagnostics for resolution process
  - Support for fallback resolution strategies in non-strict mode

## [1.0.0-RC1] - 2025-08-01

### Added
- **Core Multi-Tenancy Features**
  - Tenant context management with `TenantContext` and `TenantContextInterface`
  - Multiple tenant resolution strategies: path-based, subdomain-based, header-based, query-based, domain-based, DNS TXT, hybrid, and custom resolvers
  - DNS TXT resolver for DNS-based tenant resolution with configurable timeout and caching
  - Domain-based and hybrid resolvers for flexible domain mapping
  - Automatic tenant resolution via `TenantRequestListener`
  - Tenant registry with Doctrine and in-memory implementations

- **Database Integration**
  - Enhanced `TenantDoctrineFilter` with improved safety and debugging:
    - Safely skips entities without tenant columns by inspecting ClassMetadata
    - Properly typed parameters (UUID vs int) derived from entity mapping
    - Handles DQL with multiple aliases and join scenarios
    - Added DEBUG logging when filter cannot apply, with entity FQCN and reason
    - Support for both `TenantOwnedEntityInterface` and `AsTenantAware` attribute
    - Support for custom tenant field names via `AsTenantAware` attribute
    - Improved error handling with graceful fallbacks
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

### Added
- **PostgreSQL Row-Level Security (RLS) Integration**
  - `TenantSessionConfigurator` service for database-level tenant isolation
  - HTTP KernelRequest listener that sets PostgreSQL session variables (`app.tenant_id`)
  - Messenger middleware for worker processes to restore tenant session context
  - `tenant:rls:sync` console command for RLS policy management
  - Automatic scanning of `#[AsTenantAware]` entities for RLS policy generation
  - Idempotent RLS policy creation with `--apply` and `--force` options
  - Defense-in-depth security: RLS policies work even when Doctrine filters are disabled
  - PostgreSQL platform detection and validation
  - Configurable session variable names and policy prefixes

- **Enhanced Tenant Registry**
  - Added `findBySlug()` method to `TenantRegistryInterface`
  - Implemented `findBySlug()` in `DoctrineTenantRegistry` and `InMemoryTenantRegistry`
  - Improved Messenger integration with tenant slug-based lookups

- **Configuration Enhancements**
  - New RLS configuration section under `database.rls`
  - `enabled`, `session_variable`, and `policy_name_prefix` options
  - Automatic service registration for RLS components

- **Documentation**
  - Comprehensive RLS security documentation (`docs/rls-security.md`)
  - RLS configuration examples (`docs/examples/rls-configuration.yaml`)
  - Updated database strategies documentation with RLS information
  - Implementation summary with usage examples

- **Testing**
  - 18 new unit tests for RLS functionality
  - 3 integration tests for command registration and behavior
  - 4 functional tests proving RLS defense-in-depth protection
  - Tests properly skip when PostgreSQL is not available
  - All existing tests continue to pass (142 total tests, 338 assertions)

### Security
- **Defense-in-Depth Protection**: RLS policies provide database-level tenant isolation even if application-level filters are bypassed or disabled
- **Automatic Session Management**: Tenant context is automatically restored in worker processes
- **PostgreSQL-Native Security**: Leverages PostgreSQL's built-in Row-Level Security features

### Technical Details
- RLS only works with PostgreSQL databases and `shared_db` strategy
- Session variables are automatically set/cleared for HTTP requests and Messenger workers
- Generated policies use `tenant_id::text = current_setting('app.tenant_id', true)` pattern
- Command generates SQL like: `ALTER TABLE <table> ENABLE ROW LEVEL SECURITY; CREATE POLICY tenant_isolation_<table> ON <table> USING (...)`

### Planned Features
- UX/Admin bundle integration for tenant management UI
- Enhanced connection factory for database switching
- Tenant user interface and authentication integration
- Advanced file storage adapters (S3, etc.)
- Tenant-specific routing capabilities
