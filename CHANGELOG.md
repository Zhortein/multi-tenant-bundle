# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-RC2]

### Added
- **Observability and Monitoring**
  - PSR-14 compatible events for tenant operations monitoring:
    - `TenantResolvedEvent` - dispatched when tenant is successfully resolved
    - `TenantResolutionFailedEvent` - dispatched when tenant resolution fails
    - `TenantContextStartedEvent` - dispatched when tenant context is initialized
    - `TenantContextEndedEvent` - dispatched when tenant context is cleared
    - `TenantRlsAppliedEvent` - dispatched when PostgreSQL RLS is applied
    - `TenantHeaderRejectedEvent` - dispatched when header is rejected by allow-list
  - Vendor-neutral metrics collection system:
    - `MetricsAdapterInterface` for APM integration (Prometheus, StatsD, DataDog, etc.)
    - `NullMetricsAdapter` as default no-op implementation
    - Automatic metrics collection for tenant resolution, RLS application, and header rejections
    - Counter metrics: `tenant_resolution_total`, `tenant_rls_apply_total`, `tenant_header_rejected_total`
  - Comprehensive logging with structured context:
    - `TenantLoggingSubscriber` for automatic event logging
    - INFO/WARNING/ERROR log levels with tenant_id context
    - Detailed failure reasons and context information
  - Event dispatch integration in core components:
    - `TenantContext` dispatches context lifecycle events
    - `ChainTenantResolver` dispatches resolution and header rejection events
    - `TenantSessionConfigurator` dispatches RLS application events
  - Complete test coverage with mock adapters and event verification
  - Documentation in `docs/observability.md` with Prometheus and StatsD examples

- **Comprehensive Test Kit**
  - `WithTenantTrait` for tenant context management in tests with `withTenant()` and `withoutDoctrineTenantFilter()` methods
  - `TestData` lightweight test data builders for tenant-aware entities with seeding and counting methods
  - `TenantWebTestCase` base class for HTTP testing with resolver-aware client creation methods
  - `TenantCliTestCase` base class for CLI testing with tenant-aware command execution
  - `TenantMessengerTestCase` base class for Messenger testing with tenant stamp verification
  - `RlsIsolationTest` proving PostgreSQL Row-Level Security works as defense-in-depth
  - `ResolverChainHttpTest` for HTTP tenant resolution strategy testing
  - `MessengerTenantPropagationTest` for async message tenant context propagation
  - `CliTenantContextTest` for CLI tenant context management
  - `DecoratorsTest` for tenant-aware service decorators (cache, logging, storage)
  - `ResolverChainTest` for resolver precedence and configuration testing
  - Docker Compose setup for PostgreSQL testing with RLS policies
  - PostgreSQL session variable management for `app.tenant_id` setting
  - Test fixtures including `TestController`, `TestTenantAwareMessage`, and enhanced `TestTenant`
  - CI/CD integration examples with GitHub Actions and PostgreSQL services
  - Comprehensive documentation in `docs/testing.md` with Test Kit usage examples

- **Enhanced Console Commands System**
  - `AbstractTenantAwareCommand` base class for all tenant-aware commands
  - Global `--tenant` option support across all tenant-aware commands
  - `TENANT_ID` environment variable support with priority resolution
  - Automatic tenant context management (set/clear) during command execution
  - Enhanced `tenant:list` command with multiple output formats (table, JSON, YAML)
  - Detailed mode for `tenant:list` showing mailer/messenger DSNs with sensitive data masking
  - Enhanced `tenant:migrate` command with global tenant context support
  - Enhanced `tenant:fixtures` command with global tenant context support
  - New `tenant:impersonate` admin-only command for tenant impersonation with security restrictions
  - Tenant resolution by slug or ID with comprehensive error handling
  - Command execution in tenant context with interactive mode support
  - Security warnings and configurable restrictions for administrative commands
  - Comprehensive test coverage with 68 tests and 266 assertions

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

- **Messenger Tenant Propagation**
  - `TenantStamp` for carrying tenant ID through message queues
  - `TenantSendingMiddleware` automatically attaches tenant context to outgoing messages
  - `TenantWorkerMiddleware` restores tenant context when processing messages in workers
  - Automatic database session configuration (RLS) for tenant isolation in workers
  - Enhanced `TenantRegistryInterface` with `findById()` method for tenant lookup by ID
  - Safe handling of messages without tenant context or missing tenants
  - Exception-safe tenant context cleanup after message processing
  - Comprehensive test coverage for tenant propagation scenarios

### Security
- **Command Security Enhancements**: `tenant:impersonate` command restricted to debug mode by default with configurable security settings
- **Sensitive Data Protection**: Automatic masking of passwords and sensitive information in DSN strings across all command outputs
- **Tenant Validation**: Comprehensive tenant existence validation before command execution with clear error messages

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
