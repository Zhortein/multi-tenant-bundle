# Project Overview

This document provides a comprehensive overview of the Zhortein Multi-Tenant Bundle architecture, implementation details, and project statistics.

> 📖 **Navigation**: [← FAQ](faq.md) | [Back to Documentation Index](index.md) | [RLS Implementation Summary →](rls-implementation-summary.md)

## Project status: pre-1.0

The bundle is under active pre-1.0 verification. Supported combinations and demonstrated guarantees are defined by the compatibility matrix and required CI jobs, not by this overview.

## 📊 Project Statistics

- **PHPStan Level**: Maximum (Level 9)
- **Code Style**: Symfony standards compliant
- **Documentation**: validated links and YAML examples

## 🏗️ Architecture Overview

### Core Components

#### 1. Tenant Context Management
- `TenantContext` - Main tenant state holder
- `TenantContextInterface` - Contract for tenant context
- Thread-safe tenant state management

#### 2. Tenant Resolution System
- `PathTenantResolver` - Path-based resolution (/tenant/path)
- `SubdomainTenantResolver` - Subdomain-based resolution (tenant.domain.com)
- `HeaderTenantResolver` - Header-based resolution (X-Tenant-Slug)
- `TenantResolverInterface` - Contract for custom resolvers
- Automatic resolution via `TenantRequestListener`

#### 3. Database Integration
- `TenantDoctrineFilter` - Automatic query filtering
- `DoctrineTenantContextSynchronizer` - Atomic context, filter, identity-map, and connection lifecycle coordination
- `TenantConnectionLifecycleInterface` - Reversible shared- and multi-database transitions
- `DoctrineTenantConnectionLifecycle` - Reversible multi-database routing implementation
- `AsTenantAware` attribute for automatic entity tagging

#### 4. Tenant Registry System
- `DoctrineTenantRegistry` - Database-backed tenant storage
- `InMemoryTenantRegistry` - Memory-based tenant storage
- `TenantRegistryInterface` - Contract for custom registries

#### 5. Settings Management
- `TenantSettingsManager` - Tenant-specific configuration
- `TenantSetting` entity - Key-value settings storage
- Cache integration with configurable TTL

#### 6. Service Integrations
- `TenantMailerConfigurator` - Tenant-aware email configuration
- `TenantMailerHelper` - Simplified tenant email sending
- `TenantMessengerConfigurator` - Tenant-aware message queues
- `TenantFileStorageInterface` - File storage abstraction
- `LocalStorage` - Local file storage implementation
- `TenantAssetUploader` - Asset management helper

#### 7. Console Commands
- `TenantListCommand` - List all tenants
- `TenantCreateCommand` - Interactive tenant creation
- `TenantMigrateCommand` - Tenant-specific migrations
- `TenantSettingsClearCacheCommand` - Cache management

## 🔧 Technical Implementation

### Symfony Integration
- **Bundle Class**: `ZhorteinMultiTenantBundle` with proper extension loading
- **DI Extension**: `ZhorteinMultiTenantExtension` with comprehensive configuration
- **Configuration**: `Configuration` class with full validation
- **Compiler Passes**: Automatic service registration and optimization

### Database Strategies
- **Shared Database**: Single database with tenant filtering (default)
- **Separate Databases**: Multiple databases with connection switching
- **Automatic Filtering**: Transparent tenant isolation via Doctrine filters

### Event System
- **Request Processing**: Automatic tenant resolution from HTTP requests
- **Doctrine Integration**: Seamless database filtering activation
- **Service Configuration**: Dynamic tenant-aware service setup

### Caching Layer
- **Settings Cache**: Configurable cache pools with TTL
- **Performance Optimization**: Reduced database queries
- **Cache Invalidation**: Proper cache management commands

## 🧪 Quality Assurance

### Testing Coverage
- **Unit Tests**: 71 tests covering core functionality
- **Integration Tests**: Service interaction testing
- **Functional Tests**: End-to-end feature testing
- **Test Fixtures**: Comprehensive test data setup

### Code Quality
- **PHPStan Level Max**: Zero errors at maximum analysis level
- **PHP-CS-Fixer**: Symfony coding standards compliance
- **Strict Types**: All files use `declare(strict_types=1)`
- **Type Hints**: Complete type coverage for all methods

## 🚀 Features Delivered

### Multi-Tenancy Core
- ✅ Multiple tenant resolution strategies
- ✅ Automatic tenant context management
- ✅ Database isolation (shared and separate strategies)
- ✅ Tenant-aware service integrations
- ✅ Performance-optimized caching

### Developer Experience
- ✅ Comprehensive console commands
- ✅ Extensive configuration options
- ✅ Clear error messages and validation
- Documented public contracts with automated link and YAML validation
- ✅ Easy integration with existing Symfony apps

### Release readiness
- ✅ Security-first design with tenant isolation
- ✅ Performance optimizations with caching
- ✅ Scalable architecture for growth
- ✅ Comprehensive error handling
- ✅ Production-tested patterns

## 📁 File Structure

```
src/
├── Attribute/           # Custom attributes for tenant-aware entities
├── Command/            # Console commands for tenant management
├── Context/            # Tenant context management
├── DependencyInjection/ # Symfony DI configuration
├── Doctrine/           # Doctrine integration (filters, listeners)
├── Entity/             # Core entity interfaces and implementations
├── Event/              # Event classes for tenant lifecycle
├── EventListener/      # Event listeners for automatic tenant resolution
├── EventSubscriber/    # Event subscribers for advanced workflows
├── Exception/          # Custom exceptions for tenant operations
├── Helper/             # Utility classes and helpers
├── Mailer/             # Tenant-aware mailer integration
├── Manager/            # High-level service managers
├── Messenger/          # Tenant-aware messenger integration
├── Middleware/         # HTTP middleware for tenant resolution
├── Registry/           # Tenant registry implementations
├── Repository/         # Doctrine repositories for tenant entities
├── Resolver/           # Tenant resolution strategies
└── Storage/            # Storage abstraction for tenant data
```

## 🔄 Development Workflow

### Code Quality Pipeline
1. **Development**: Write code with strict types and documentation
2. **Testing**: Run comprehensive test suite
3. **Static Analysis**: PHPStan max level validation
4. **Code Style**: PHP-CS-Fixer formatting
5. **Integration**: Full bundle validation

### Available Commands
```bash
# Development
make dev-setup          # Complete development setup
make dev-check          # Run all development checks
make quick-check        # Quick development verification

# Testing
make test               # Run all tests
make test-unit          # Run unit tests only
make test-integration   # Run integration tests
make test-coverage      # Generate coverage report

# Quality Assurance
make phpstan            # Static analysis
make csfixer            # Fix code style
make csfixer-check      # Check code style

# Bundle Management
make bundle-validate    # Validate bundle structure
make clean              # Clean generated files
```

## 🎯 Usage Examples

### Basic Tenant Access
```php
public function dashboard(TenantContextInterface $tenantContext): Response
{
    $tenant = $tenantContext->getTenant();
    // All queries automatically filtered by tenant
    $data = $this->repository->findAll();
    return $this->render('dashboard.html.twig', ['data' => $data]);
}
```

### Tenant Settings
```php
public function configure(TenantSettingsManager $settings): Response
{
    $theme = $settings->get('theme', 'default');
    $settings->set('theme', 'dark');
    return $this->render('settings.html.twig');
}
```

### Custom Resolver
```php
class ApiKeyTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface
    {
        $apiKey = $request->headers->get('X-API-Key');
        return $this->tenantRegistry->getByApiKey($apiKey);
    }
}
```

## Demonstrated project checks

- ✅ **100% PHPStan Compliance**: Zero errors at maximum level
- **Comprehensive test suite**: PHPUnit and effective PostgreSQL RLS run as required CI checks
- ✅ **Complete Documentation**: README, CHANGELOG, CONTRIBUTING, and docs/
- **Release readiness**: not yet declared; follow the release checklist and compatibility evidence
- ✅ **Developer Friendly**: Easy setup, clear examples, extensive configuration
- ✅ **Symfony Best Practices**: Following all Symfony 7+ conventions
- ✅ **Modern PHP**: PHP 8.3+ features with strict typing
- ✅ **Extensible Architecture**: Interface-based design for customization

## Release status

The bundle is not yet a published stable release. Current preparation covers:
- Candidate validation in the external consumer and demo
- ✅ Community contribution
- Package publication only after an explicitly authorized release
- ✅ Documentation hosting
- No published tag exists yet

The bundle provides a pre-1.0 fail-closed multi-tenant implementation for Symfony applications, with PostgreSQL 16+ RLS available as defense in depth.
