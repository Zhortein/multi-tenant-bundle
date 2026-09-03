# Internal Bundle Test Suite

This directory contains the bundle contributor suite. Its classes under
`Zhortein\MultiTenantBundle\Tests` are loaded only through Composer
`autoload-dev`; they are not distributed consumer APIs.

The public consumer Test Kit lives under `src/Test` in the
`Zhortein\MultiTenantBundle\Test` namespace. See `docs/testing.md` and the
external `tests/ConsumerApp` fixture.

## Contributor suites

- `Unit/` tests isolated classes and public contracts.
- `Integration/` tests service interactions and application lifecycles.
- `Functional/` tests compiled configuration and Doctrine behavior.
- `ConsumerApp/` installs the bundle outside the repository and proves public
  package autoload, consumer-defined tenants, kernel/web lifecycle, and both
  database strategies.
- `Integration/RlsIsolationTest.php` and
  `Functional/Database/RlsIntegrationTest.php` execute effective PostgreSQL RLS
  checks as a non-superuser application role.
- `Integration/Command/MigrateTenantsCommandTest.php` executes the real Symfony
  Console command against PostgreSQL and verifies dry-run immutability, ordered
  schema/metadata changes, idempotence, A/B/A database isolation, failures, and
  cleanup.
- `Toolkit/` and `Fixtures/` are internal contributor utilities only.

## Commands

Run commands through the documented Docker environment:

```shell
make composer-validate
make phpstan
make csfixer-check
make test
make test-with-postgres
make test-tenant-migrate
```

`make test-with-postgres-16` and `make test-with-postgres-18` start the two
mandatory PostgreSQL versions, create the restricted
application role, execute the real RLS and `tenant-migrate` groups, and stop the
environment. A green suite with skipped database tests is not effective
validation.

The Compatibility workflow additionally covers the supported PHP, Symfony,
Doctrine ORM, DBAL, shared-database, multi-database, and external-consumer
matrix. It pins DoctrineMigrationsBundle 3.4, 3.7, and 4.0.1 with resolvable
migration-core 3.7.4 and 3.9.7 graphs, runs one shared Consumer App command recipe, and
installs a Git-archive candidate in a fresh Composer consumer. Internal fixtures
must never be copied into public Test Kit code.
