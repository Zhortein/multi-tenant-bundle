# Zhortein MultiTenantBundle

## 📋 Project Overview

**Zhortein MultiTenantBundle** is a Symfony 7+ bundle designed to provide clean, flexible, and modular multi-tenancy capabilities for Symfony applications. This bundle enables applications to serve multiple tenants (clients/organizations) from a single codebase while maintaining data isolation and tenant-specific configurations.

### 🎯 Purpose
The bundle addresses the common need for SaaS applications to serve multiple customers (tenants) with isolated data, configurations, and potentially different database connections, while maintaining a single application codebase.

## 🏗️ Technical Stack

- **PHP**: >= 8.3
- **Symfony**: >= 7.0
- **Database**: PostgreSQL 16 via Doctrine ORM
- **Frontend**: Stimulus, Turbo, AlpineJS, Tailwind 4 or Bootstrap 5 (when needed)
- **Translations**: XLIFF format
- **Code Quality**: PHPStan (max level), PHP-CS-Fixer, PHPUnit
- **Architecture**: Structured, tested, documented (English), eco-conception principles

## 📦 Package Information

- **Name**: `zhortein/multi-tenant-bundle`
- **Type**: Symfony Bundle
- **Version**: 1.0.0
- **License**: MIT
- **Author**: David Renard (david.renard.21@free.fr)
- **Homepage**: https://www.david-renard.fr
- **Repository**: https://github.com/Zhortein/multi-tenant-bundle
- **Development Branch**: `develop`

## ✨ Core Features

### Current Features (MVP)
- **Tenant Resolution**: Multiple strategies (subdomain, path, custom)
- **Tenant Context**: Injectable service for accessing current tenant
- **Doctrine Tenant Filter**: Automatic data isolation at ORM level
- **CLI Commands**: Tenant management commands
- **Multi-Service Support**: Ready for tenant-aware storage, mailer, and messenger

### 🏛️ Architecture Components

#### 📁 Directory Structure
```
src/
├── Attribute/           # Tenant-aware attributes
├── Command/            # CLI commands for tenant management
├── Context/            # Tenant context management
├── DependencyInjection/ # Bundle configuration and services
├── Doctrine/           # Doctrine integration and filters
├── Entity/             # Base entities and interfaces
├── Event/              # Tenant-related events
├── EventSubscriber/    # Event subscribers
├── Helper/             # Utility helpers
├── Listener/           # Request listeners
├── Mailer/             # Tenant-aware mailer
├── Manager/            # Business logic managers
├── Messenger/          # Tenant-aware messenger
├── Registry/           # Tenant registries
├── Repository/         # Data repositories
├── Resolver/           # Tenant resolution strategies
└── Storage/            # File storage abstractions
```

#### 🔧 Key Components

1. **Tenant Resolution**
   - `SubdomainTenantResolver`: Resolves tenant from subdomain
   - `PathTenantResolver`: Resolves tenant from URL path
   - Extensible resolver interface for custom strategies

2. **Context Management**
   - `TenantContext`: Central service for current tenant access
   - `TenantContextInterface`: Contract for tenant context

3. **Data Isolation**
   - `TenantDoctrineFilter`: Automatic filtering of tenant-owned entities
   - `TenantTrait`: Trait for entities that belong to tenants
   - `TenantOwnedEntityInterface`: Interface for tenant-owned entities

4. **Configuration Management**
   - `TenantSettingsManager`: Dynamic tenant-specific settings
   - `TenantSetting`: Entity for storing tenant configurations

5. **Multi-Service Support**
   - `TenantMailerHelper`: Tenant-aware email sending
   - `TenantAssetUploader`: Tenant-specific file uploads
   - Messenger integration for tenant-aware async processing

## 🚀 Installation & Usage

### Basic Configuration
```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
  tenant_entity: App\Entity\Tenant
  resolver: path # or subdomain
```

### CLI Commands
```bash
# Create a new tenant
php bin/console tenant:create genlis "Ville de Genlis"

# List all tenants
php bin/console tenant:list

# Migrate tenants
php bin/console tenant:migrate

# Clear tenant settings cache
php bin/console tenant:clear-settings-cache
```

## 🧪 Development & Quality Assurance

### Development Tools
- **Docker-based Development**: PHP 8.3 container setup
- **Makefile**: Automated development tasks
- **Composer Scripts**: Code quality automation

### Quality Tools
- **PHPStan**: Static analysis (max level)
- **PHP-CS-Fixer**: Code style enforcement (@Symfony rules)
- **PHPUnit**: Unit and integration testing
- **Security Advisories**: Roave security advisories integration

### Available Make Commands
```bash
make help          # Show available commands
make installdeps   # Install Composer dependencies
make updatedeps    # Update Composer dependencies
make csfixer       # Run PHP-CS-Fixer
make phpstan       # Run PHPStan analysis
make test          # Run PHPUnit tests
make php           # Open PHP 8.3 shell in container
```

## 📚 Documentation

The bundle includes comprehensive documentation in the `docs/` directory:
- Installation guide
- Configuration options
- Usage examples
- Command reference
- Helper utilities
- Storage configuration
- Testing guidelines
- Tenant entity setup
- Doctrine filter configuration

## 🔄 Development Status

### Current State
The project is actively under development and approaching **Release Candidate** status, with plans for a **stable 1.0 release** in the near future.

**Completed Features:**
- ✅ Core multi-tenancy infrastructure
- ✅ Tenant resolution (subdomain, path)
- ✅ Doctrine integration and filtering
- ✅ Configuration system
- ✅ CLI commands
- ✅ Event system
- ✅ Tenant-aware services (Mailer, Messenger, Storage)
- ✅ Settings management system

**Development Branch:** `develop` (active development)

### Roadmap to Stable Release
- 🔄 Final testing and bug fixes
- 🔄 Documentation completion
- 🔄 Performance optimizations
- 🔄 Extended test coverage
- 🎯 **Release Candidate** (imminent)
- 🎯 **Stable v1.0.0** (coming soon)

## 🤝 Contributing

The project is currently in **Release Candidate** phase and welcomes contributions! The project follows Symfony best practices and maintains high code quality standards:

### Development Guidelines
- All code must pass PHPStan analysis at maximum level
- Code style must conform to @Symfony rules
- All features must be tested
- Documentation must be in English
- Eco-conception principles should be followed

### Getting Started
1. Fork the repository from https://github.com/Zhortein/multi-tenant-bundle
2. Create a feature branch from `develop`
3. Follow the quality standards outlined above
4. Submit a pull request to the `develop` branch

### Repository Structure
- **Main Branch**: `main` (stable releases)
- **Development Branch**: `develop` (active development)
- **Feature Branches**: `feature/your-feature-name`

## 📄 License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## 🔗 Links

- **GitHub Repository**: https://github.com/Zhortein/multi-tenant-bundle
- **Development Branch**: https://github.com/Zhortein/multi-tenant-bundle/tree/develop
- **Author Website**: https://www.david-renard.fr
- **Documentation**: See `docs/` directory
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)

---

*This bundle is designed to provide enterprise-grade multi-tenancy capabilities while maintaining simplicity and flexibility for developers.*