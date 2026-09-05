# Repository Working Guidelines

## Scope and relationship

This repository is the public, generic Symfony multi-tenancy bundle. Keep it
independent from any product-specific application, including Services Locaux.
Product needs may motivate generic extension points, but product concepts must
not enter the bundle API or configuration.

The companion `Zhortein/multi-tenant-demo` repository is the reference consumer.
Changes to public APIs, configuration, dependency constraints, or installation
instructions must be validated in that application.

## Git workflow

- `main` is the published integration branch; `develop` is the active
  development branch.
- Create focused branches from `develop` for implementation work and target
  pull requests to `develop`, unless a release workflow explicitly says
  otherwise.
- Do not commit generated files, Composer binaries, `vendor/`, coverage, cache,
  or local environment files.
- Keep repository guidance in `AGENTS.md`; update it when workflows, supported
  environments, or public contribution expectations change.
- Preserve unrelated local changes and inspect `git status --short` before and
  after every task.

## Environment and dependencies

Use the Docker-based Make targets. Do not install PHP, Composer, or project
tools on the host.

- `make installdeps`: restore dependencies (may create ignored local files).
- `make composer-validate`: validate `composer.json`.
- `make test`: run the PHPUnit suite.
- `make test-unit`: run unit tests.
- `make test-integration`: run integration tests.
- `make test-with-postgres`: run PostgreSQL/RLS checks.
- `make phpstan`: run PHPStan with `phpstan.neon` at level max.
- `make csfixer-check`: check Symfony coding style without modifying files.
- `make validate-testkit`: validate test-kit wiring.
- `make dev-check`: documented local quality gate.

Do not run dependency updates or commands that alter a tracked lock/configuration
file without explicit authorization. The bundle intentionally ignores
`composer.lock`; compatibility must be demonstrated with an explicit dependency
matrix in CI.

## Architecture and compatibility

- Preserve the PSR-4 root `Zhortein\MultiTenantBundle\` mapped to `src/`.
- Treat classes, interfaces, attributes, traits, service IDs, configuration
  keys, console commands, events, and documented extension points as public API.
- Prefer additive changes and deprecation paths. Document intentional breaking
  changes and provide migration guidance.
- Optional components (Mailer, Twig, Monolog, PSR-16 and Scheduler) must remain
  optional at installation and fail clearly when enabled without prerequisites.
  Messenger remains a required runtime component for RC9 compatibility; its
  integration must support explicit disabling independently of installation.
- Shared-database isolation must be tested both through Doctrine ORM and with
  the Doctrine filter bypassed where PostgreSQL RLS is claimed as defense in
  depth.
- Multi-database behavior must prove connection/context reset between tenants
  and long-running workers.

## Tests and documentation

Add regression tests for every behavior change. Security-sensitive changes need
cross-tenant negative tests. Update the demo when consumer behavior changes.
Documentation examples must be executable against the current configuration
tree and public namespaces.

Before handoff, run the focused tests and, when practical:

1. `make composer-validate`
2. `make phpstan`
3. `make csfixer-check`
4. `make test`
5. `make test-with-postgres` for database-isolation changes

Report skipped checks and PHPUnit notices/skips accurately.


## Public communication and security

Write repository documentation, issues, pull requests, review comments,
changelog entries, release notes, branch names, and commit messages in
professional English. Never publish credentials, private infrastructure
details, production data, or product-specific information. Treat tenant
isolation regressions, path traversal, context leakage, and unsafe defaults as
security-sensitive changes and disclose them responsibly.
