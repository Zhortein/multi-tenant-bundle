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
- `Toolkit/` and `Fixtures/` are internal contributor utilities only.

## Commands

Run commands through the documented Docker environment:

```shell
make composer-validate
make phpstan
make csfixer-check
make test
make test-with-postgres
```

`make test-with-postgres` starts PostgreSQL 16, creates the restricted
application role, executes the real RLS group, and stops the environment. A
green suite with skipped RLS tests is not effective RLS validation.

The Compatibility workflow additionally covers the supported PHP, Symfony,
Doctrine ORM, DBAL, shared-database, multi-database, and external-consumer
matrix. Internal fixtures must never be copied into public Test Kit code.
