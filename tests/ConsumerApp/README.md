# Consumer Application Fixture

This fixture is a standalone Symfony application used only for compatibility validation. The workflow copies it outside the bundle source, configures the bundle as a non-symlinked Composer path repository, and installs it under the external application's vendor directory. Running outside the source is required by current Composer releases and proves that the bundle behaves as an external dependency.

GitHub Actions selects Symfony 7.4, 8.0, or 8.1, installs the fixture dependencies, and boots separate `shared_db` and `multi_db` kernels. Both configurations must compile with the optional Mailer and Messenger integrations enabled and without Twig, Monolog, or PSR-16. PHPUnit then exercises the public Test Kit through production package autoload for both strategies.

The fixture also exercises an initialized tenant-aware cache through the real
Symfony `services_resetter`, repeated requests with `KernelBrowser::disableReboot()`,
automatic infrastructure resolution, disabled automatic resolution, explicit
late loading, resolver exceptions, and a dedicated lazy-firewall SecurityBundle
configuration. The main bundle remains installable without SecurityBundle.

From the repository root, reproduce the locked consumer fixture with PHP 8.5.9 in a fresh directory outside the repository:

```bash
cp -R tests/ConsumerApp /tmp/multi-tenant-consumer
docker run --rm -v "$PWD":/bundle -v /tmp/multi-tenant-consumer:/consumer -w /consumer composer:2 composer config --json repositories.bundle '{"type":"path","url":"/bundle","options":{"symlink":false}}'
docker run --rm -v "$PWD":/bundle -v /tmp/multi-tenant-consumer:/consumer -w /consumer composer:2 composer update --prefer-dist --no-progress --no-interaction
docker run --rm -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.5.9-cli php bin/validate.php
docker run --rm -e DATABASE_STRATEGY=shared_db -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.5.9-cli vendor/bin/phpunit --no-coverage
docker run --rm -e DATABASE_STRATEGY=multi_db -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.5.9-cli php bin/validate.php
docker run --rm -e DATABASE_STRATEGY=multi_db -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.5.9-cli vendor/bin/phpunit --no-coverage
```

The Symfony 8.1 job resolves the same direct components at `~8.1.0` and executes both container and Test Kit validations with PHP 8.5. The suite proves that consumer entities use only the public `TenantInterface`, internal `Tests\Toolkit` classes are unavailable, exceptions restore context, and sequential A/B/A plus kernel reboot scenarios do not leak context. Use an isolated copy if a different dependency resolution is already present in the fixture.

The fixture also compiles a fresh production container while recording named-autowiring deprecations. It verifies that `MigrateTenantsCommand` receives Doctrine's default connection both in the single-connection configuration and with explicit `default` and `reporting` connections and EntityManagers, then repeats the check with a custom-named `primary` default connection. The command argument references Doctrine's stable `Connection` type alias explicitly; DoctrineBundle 2.19 and 3.3 both map that alias to the configured default connection. This covers the only bundle constructor parameter that matches a framework-generated named alias. The other audited integration arguments are either unqualified type autowiring (Doctrine connections and EntityManagers, loggers and mailers) or explicit service references (configured cache pools, decorated cache services, migration configuration, Messenger factories and transports), so they do not require `#[Target]`.

The stable `Consumer / Services Locaux exact graph` CI check pins the confirmed consumer graph exactly: PHP 8.5.9, FrameworkBundle 8.1.5, Doctrine ORM 3.6.8, DBAL 4.4.4, DoctrineBundle 3.3.1, DoctrineMigrationsBundle 4.0.1, Doctrine Migrations core 3.9.7, and PostgreSQL 16 and 18. It compiles the container, validates Doctrine metadata, executes the real `tenant:migrate --dry-run`/normal/idempotent recipe, seeds two tenants and selects each through the command, exercises an ordinary Doctrine migration down/up cycle, and proves the essential fail-closed Doctrine and Messenger boundaries. Separate required jobs run the same command recipe on DoctrineMigrationsBundle/core 3.4.0/3.7.4 and 3.7.0/3.9.7 with PostgreSQL 16 and 18. The check-name prefix is a CI contract and must remain stable if selected by a repository ruleset.

`bin/test-tenant-migrate.sh` is the shared distribution recipe. Its dry run must
render the probe SQL while leaving both schema and metadata absent; normal
execution must create both expected tables and metadata rows; the second run
must be idempotent. It then provisions two isolated PostgreSQL tenant databases,
repeats dry-run/normal/idempotent execution across both, verifies targeted
A/B/A selection, executes a controlled failing migration without corrupting
either database, exercises an empty migration set, and rejects an unknown
tenant. `bin/assert-migration-graph.php` prevents a matrix row from passing
under a different Bundle, core, or DBAL patch than its published job name.

The `Candidate archive` jobs use `git archive` for the exact commit, install the
ZIP as `dev-candidate` through a temporary Composer package definition, compare
the installed command bytes with the checkout, compile a production kernel, and
repeat the command recipe on the core 3.7/Bundle 3.4/PostgreSQL 16 and core
3.9/Bundle 4/PostgreSQL 18 graphs. No published Composer file contains a local
repository, and the temporary version does not claim that the next RC exists.
