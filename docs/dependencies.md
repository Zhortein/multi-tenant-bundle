# Dependency Classification

The bundle declares components directly and does not depend on Symfony packs. This keeps production installations predictable and prevents optional integrations from being installed transitively.

## Required runtime components

The core runtime requires:

- Doctrine DBAL, ORM, Persistence, DoctrineBundle, Migrations, and DoctrineMigrationsBundle for tenant registries, filters, schema management, and tenant migration commands;
- Symfony Config, Console, DependencyInjection, EventDispatcher, Filesystem, Finder, HttpFoundation, and HttpKernel;
- Symfony Messenger because the public PostgreSQL RLS session configurator also provides tenant propagation middleware for workers;
- PSR-6 cache, PSR event dispatcher, and PSR logger contracts.

Local tenant-aware storage is included and requires no storage adapter package. PostgreSQL >= 16 is supported and PostgreSQL 16 and PostgreSQL 18 are mandatory RLS validation targets.

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

Messenger remains a required runtime component in RC10, preserving RC9's
transitive dependency contract and the public `TenantSessionConfigurator`
middleware interface. Its integration can independently be disabled with
`zhortein_multi_tenant.messenger.enabled: false`; disabled integration registers
no bundle Messenger services and leaves Symfony's bus chains unchanged.

## Optional consumer test dependencies

`TenantContextScope` needs no additional package. Applications extending the
public `TenantKernelTestCase` need PHPUnit and FrameworkBundle in their
development environment. Applications extending `TenantWebTestCase` also need
Symfony BrowserKit and DomCrawler at the same Symfony version as the
application. These remain consumer `require-dev` dependencies and are not
promoted to bundle production requirements.

## Development-only tools

PHPUnit, PHPStan and its extensions, PHP-CS-Fixer, Symfony BrowserKit and DomCrawler, the Symfony PHPUnit Bridge, and security-advisory constraints are development dependencies. They are not installed in a production-only Composer installation.

## Verification

GitHub Actions resolves a production-only installation with `composer update
--no-dev`, confirms that Mailer, Twig, Monolog, PSR-16 and Scheduler are absent,
and verifies that Messenger and the public session configurator remain
loadable. It compiles the container with Messenger integration explicitly
disabled and asserts that its integration services are absent. The compatibility
matrix installs the development integrations and runs their unit, integration
and functional scenarios. The [composition audit](audit-rc9-messenger-composition.md)
records why the optional-Messenger experiment was rejected for RC10.
