---
description: Repository Information Overview
alwaysApply: true
---

# Zhortein Multi-Tenant Bundle Information

## Summary
A comprehensive Symfony 7+ bundle for building multi-tenant applications with PostgreSQL 16 support, featuring multiple resolution strategies, tenant-aware services, and automatic database filtering.

## Structure
- **src/**: Core bundle code organized by component
- **tests/**: Test suite with unit, integration, and functional tests
- **docs/**: Comprehensive documentation and examples
- **config/**: Bundle configuration files
- **assets/**: Frontend assets (if any)

## Language & Runtime
**Language**: PHP
**Version**: >= 8.3
**Framework**: Symfony >= 7.0
**Database**: PostgreSQL 16 (via Doctrine ORM)
**Build System**: Composer
**Package Manager**: Composer

## Dependencies
**Main Dependencies**:
- doctrine/doctrine-bundle: ^2.7
- doctrine/doctrine-migrations-bundle: ^3.0
- symfony/cache: ^7.0
- symfony/config: ^7.0
- symfony/contracts: ^3.0
- symfony/dependency-injection: ^7.0
- symfony/filesystem: ^7.0
- symfony/http-kernel: ^7.0
- symfony/orm-pack: ^2.0

**Development Dependencies**:
- phpstan/phpstan: ^2.1 (max level)
- phpunit/phpunit: ^12.2.5
- friendsofphp/php-cs-fixer: ^v3.75.0
- symfony/phpunit-bridge: ^7.3
- symfony/test-pack: ^1.0

## Build & Installation
```bash
# Install via Composer
composer require zhortein/multi-tenant-bundle

# Development setup
make dev-setup

# Run tests
make test
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
```

## Testing
**Framework**: PHPUnit 12
**Test Location**: tests/ directory
**Structure**:
- Unit tests: tests/Unit/
- Integration tests: tests/Integration/
- Functional tests: tests/Functional/
**Run Command**:
```bash
make test          # All tests
make test-unit     # Unit tests only
make test-coverage # With coverage report
```

## Core Components
- **Tenant Context**: Thread-safe tenant state management
- **Tenant Resolution**: Multiple strategies (subdomain, path, header, DNS)
- **Database Integration**: Automatic query filtering with Doctrine
- **Tenant Registry**: Database and in-memory implementations
- **Settings Management**: Tenant-specific configuration with caching
- **Service Integrations**: Mailer, Messenger, and file storage
- **Console Commands**: Tenant management and migrations

## Code Quality
- PHPStan at maximum level
- PHP-CS-Fixer with Symfony rules
- Strict typing throughout
- Comprehensive test suite
- Interface-based architecture
- Follows Symfony best practices