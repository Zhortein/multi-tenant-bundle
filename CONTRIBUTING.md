# Contributing to Zhortein Multi-Tenant Bundle

Thank you for your interest in contributing to the Zhortein Multi-Tenant Bundle! This document provides guidelines and information for contributors.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Making Changes](#making-changes)
- [Testing](#testing)
- [Code Quality](#code-quality)
- [Submitting Changes](#submitting-changes)
- [Release Process](#release-process)

## Code of Conduct

This project adheres to a code of conduct that we expect all contributors to follow. Please be respectful and constructive in all interactions.

## Getting Started

### Prerequisites

- **PHP**: >= 8.3
- **Composer**: Latest stable version
- **Docker**: For running tests and development environment
- **Git**: For version control

### Fork and Clone

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/multi-tenant-bundle.git
   cd multi-tenant-bundle
   ```

3. Add the upstream repository:
   ```bash
   git remote add upstream https://github.com/zhortein/multi-tenant-bundle.git
   ```

## Development Setup

### Install Dependencies

```bash
composer install
```

### Available Make Commands

The project uses a Makefile for common development tasks:

```bash
# Install dependencies
make install

# Run all tests
make test

# Run unit tests only
make test-unit

# Run tests with coverage
make test-coverage

# Run PHPStan static analysis
make phpstan

# Fix code style
make cs-fix

# Check code style
make cs-check

# Run all quality checks
make qa
```

### Docker Environment

The project includes Docker configuration for consistent development:

```bash
# Run tests in Docker
make test

# Run PHPStan in Docker
make phpstan

# Fix code style in Docker
make cs-fix
```

## Making Changes

### Branch Naming

Use descriptive branch names:
- `feature/add-new-resolver` - for new features
- `bugfix/fix-tenant-context` - for bug fixes
- `docs/update-installation` - for documentation updates
- `refactor/improve-performance` - for refactoring

### Commit Messages

Follow conventional commit format:
```
type(scope): description

[optional body]

[optional footer]
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

Examples:
```
feat(resolver): add header-based tenant resolver

Add support for resolving tenants from HTTP headers.
This allows for API-based tenant resolution.

Closes #123
```

```
fix(doctrine): handle null tenant in filter

The Doctrine filter was throwing exceptions when no tenant
was set in the context. Now it gracefully handles null values.

Fixes #456
```

## Testing

### Writing Tests

- **Unit Tests**: Test individual classes in isolation
- **Integration Tests**: Test component interactions
- **Functional Tests**: Test complete features end-to-end

### Test Structure

```
tests/
├── Unit/           # Unit tests
├── Integration/    # Integration tests
├── Functional/     # Functional tests
└── Fixtures/       # Test fixtures and data
```

### Test Guidelines

1. **Coverage**: Aim for high test coverage, especially for new features
2. **Naming**: Use descriptive test method names
3. **Arrange-Act-Assert**: Structure tests clearly
4. **Isolation**: Tests should not depend on each other
5. **Data Providers**: Use data providers for testing multiple scenarios

### Example Test

```php
<?php

namespace Zhortein\MultiTenantBundle\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class TenantContextTest extends TestCase
{
    public function testSetAndGetTenant(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $context = new TenantContext();

        // Act
        $context->setTenant($tenant);

        // Assert
        $this->assertSame($tenant, $context->getTenant());
    }

    public function testGetTenantReturnsNullWhenNotSet(): void
    {
        // Arrange
        $context = new TenantContext();

        // Act & Assert
        $this->assertNull($context->getTenant());
    }
}
```

### Running Tests

```bash
# Run all tests
make test

# Run specific test suite
make test-unit
vendor/bin/phpunit tests/Integration
vendor/bin/phpunit tests/Functional

# Run with coverage
make test-coverage

# Run specific test class
vendor/bin/phpunit tests/Unit/Context/TenantContextTest.php

# Run specific test method
vendor/bin/phpunit --filter testSetAndGetTenant tests/Unit/Context/TenantContextTest.php
```

## Code Quality

### Static Analysis

The project uses PHPStan at maximum level:

```bash
make phpstan
```

All code must pass PHPStan analysis without errors.

### Code Style

The project uses PHP-CS-Fixer with Symfony rules:

```bash
# Check code style
make cs-check

# Fix code style
make cs-fix
```

### Quality Standards

- **PHP 8.3+ Features**: Use modern PHP features and type declarations
- **Strict Types**: All PHP files must declare `strict_types=1`
- **Type Hints**: Use type hints for all parameters and return values
- **Documentation**: All public methods must have PHPDoc comments
- **Immutability**: Prefer immutable objects where possible
- **SOLID Principles**: Follow SOLID design principles
- **Symfony Best Practices**: Adhere to Symfony coding standards

### Example Code Style

```php
<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Context;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Manages the current tenant context for the application.
 *
 * This service holds the currently active tenant and provides
 * methods to access and modify the tenant context.
 */
final class TenantContext implements TenantContextInterface
{
    private ?TenantInterface $tenant = null;

    /**
     * {@inheritdoc}
     */
    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    /**
     * {@inheritdoc}
     */
    public function setTenant(?TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}
```

## Submitting Changes

### Pull Request Process

1. **Update your fork**:
   ```bash
   git fetch upstream
   git checkout main
   git merge upstream/main
   ```

2. **Create a feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```

3. **Make your changes** following the guidelines above

4. **Run quality checks**:
   ```bash
   make qa
   ```

5. **Commit your changes**:
   ```bash
   git add .
   git commit -m "feat: add your feature description"
   ```

6. **Push to your fork**:
   ```bash
   git push origin feature/your-feature-name
   ```

7. **Create a Pull Request** on GitHub

### Pull Request Guidelines

- **Title**: Use a clear, descriptive title
- **Description**: Explain what changes you made and why
- **Tests**: Include tests for new functionality
- **Documentation**: Update documentation if needed
- **Breaking Changes**: Clearly mark any breaking changes
- **Issue References**: Reference related issues

### Pull Request Template

```markdown
## Description
Brief description of the changes.

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Testing
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] New tests added for new functionality
- [ ] Manual testing completed

## Checklist
- [ ] Code follows the project's coding standards
- [ ] Self-review of code completed
- [ ] Code is commented, particularly in hard-to-understand areas
- [ ] Documentation updated
- [ ] No new warnings or errors introduced
```

## Release Process

### Versioning

The project follows [Semantic Versioning](https://semver.org/):
- **MAJOR**: Breaking changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes (backward compatible)

### Release Checklist

1. Update CHANGELOG.md
2. Update version in composer.json
3. Run full test suite
4. Create release tag
5. Update documentation
6. Announce release

## Getting Help

- **Documentation**: Check the [docs](docs/) folder
- **Issues**: Search existing [GitHub Issues](https://github.com/zhortein/multi-tenant-bundle/issues)
- **Discussions**: Join [GitHub Discussions](https://github.com/zhortein/multi-tenant-bundle/discussions)
- **Contact**: Reach out to [David Renard](https://www.david-renard.fr)

## Recognition

Contributors will be recognized in:
- CHANGELOG.md for significant contributions
- README.md contributors section
- GitHub contributors page

Thank you for contributing to the Zhortein Multi-Tenant Bundle!