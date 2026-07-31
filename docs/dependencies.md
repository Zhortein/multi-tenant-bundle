# Dependency Classification

The bundle declares components directly and does not depend on Symfony packs. This keeps production installations predictable and prevents optional integrations from being installed transitively.

## Required runtime components

The core runtime requires:

- Doctrine DBAL, ORM, Persistence, DoctrineBundle, Migrations, and DoctrineMigrationsBundle for tenant registries, filters, schema management, and tenant migration commands;
- Symfony Config, Console, DependencyInjection, EventDispatcher, Filesystem, Finder, HttpFoundation, and HttpKernel;
- Symfony Messenger because the public PostgreSQL RLS session configurator also provides tenant propagation middleware for workers;
- PSR-6 cache, PSR event dispatcher, and PSR logger contracts.

Local tenant-aware storage is included and requires no storage adapter package. PostgreSQL 16 remains the supported reference environment for RLS.

## Optional integrations

Install only the integrations used by the application:

```bash
# Tenant-aware mail delivery
composer require symfony/mailer

# Templated tenant-aware mail
composer require symfony/mailer symfony/twig-bundle

# Tenant-aware Monolog processor
composer require monolog/monolog

# PSR-16 cache decorator
composer require psr/simple-cache:^3.0
```

Mailer services are not registered when Symfony Mailer is unavailable. Templated mail additionally requires Twig. The Monolog processor is not registered when Monolog is unavailable. PSR Simple Cache versions earlier than 3.0 are incompatible with the typed PSR-16 decorator and are explicitly rejected.

Messenger is not optional in the current public API because `TenantSessionConfigurator` implements Symfony Messenger middleware. Changing that contract would require a separate backward-compatibility decision.

## Development-only tools

PHPUnit, PHPStan and its extensions, PHP-CS-Fixer, Symfony BrowserKit and DomCrawler, the Symfony PHPUnit Bridge, and security-advisory constraints are development dependencies. They are not installed in a production-only Composer installation.

## Verification

GitHub Actions resolves a production-only installation with `composer update --no-dev`, confirms that Mailer, Twig, Monolog, and PSR-16 are absent, and compiles the bundle container with those integrations unavailable. The compatibility matrix installs the development integrations and runs their existing unit, integration, and functional scenarios.
