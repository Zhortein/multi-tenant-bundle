# Tenant migrations

`tenant:migrate` applies the consumer's configured Doctrine migrations to the
connection selected by the bundle's database strategy.

> **Navigation:** [← Fixtures](fixtures.md) | [Documentation index](index.md) |
> [RLS security →](rls-security.md)

## Release status and compatibility

`v1.0.0-rc.6` contains a known defect: both normal and dry-run execution call
the Doctrine migrator with an obsolete argument list and fail on the
DoctrineMigrationsBundle 4.0.1 consumer graph. The fix is being prepared for a
subsequent release candidate; that candidate is not published yet. A blanket
dependency downgrade is not recommended as a general workaround.

The required compatibility workflow exercises the real Console command with:

| DoctrineMigrationsBundle | Doctrine Migrations core | DoctrineBundle | DBAL | PHP | PostgreSQL |
|---|---|---|---|---|---|
| 3.4.0 | 3.7.4 | 2.19.0 | 3.8.7 | 8.3 | 16 and 18 |
| 3.7.0 | 3.9.7 | 3.3.1 | 4.4.4 | 8.4 | 16 and 18 |
| 4.0.1 | 3.9.7 | 3.3.1 | 4.4.4 | 8.5.9 | 16 and 18 |

DoctrineMigrationsBundle 4.0.1 still uses the `doctrine/migrations` 3.x core;
there is no core 4.0.1 package in this graph. The distinction matters when
diagnosing or constraining Composer dependencies. Core 3.4.x cannot resolve
with the bundle's Symfony 7.4 floor because its Symfony component constraints
end at Symfony 6; core 3.7.4 is the oldest pinned engine proof.

## Configuration

Configure migrations through DoctrineMigrationsBundle as usual:

```yaml
doctrine_migrations:
    migrations_paths:
        'DoctrineMigrations': '%kernel.project_dir%/migrations'
    all_or_nothing: true
    storage:
        table_storage:
            table_name: doctrine_migration_versions
```

Then select the bundle strategy:

```yaml
zhortein_multi_tenant:
    database:
        strategy: shared_db # or multi_db
```

For `multi_db`, the application's
`TenantConnectionParametersProviderInterface` must return the complete DBAL
parameters for the selected tenant. The command never falls back to an
unscoped connection when tenant resolution fails.

## Command contract

```bash
# Migrate the selected strategy to the latest available version
php bin/console tenant:migrate

# Preview the same plan without changing schema or migration metadata
php bin/console tenant:migrate --dry-run

# Select one tenant by slug or ID (multi_db)
php bin/console tenant:migrate --tenant=acme

# TENANT_ID is the non-interactive equivalent of --tenant
TENANT_ID=acme php bin/console tenant:migrate

# Accept an empty configured migration set
php bin/console tenant:migrate --allow-no-migration
```

`--tenant` takes precedence over `TENANT_ID`. An unknown tenant is rejected
before migration work starts. The command currently targets Doctrine's
`latest` version alias; it does not provide `--to`, `--from`, `--single`,
status, version-marking, or rollback subcommands.

### Shared database

With `shared_db`, the command calculates and executes one plan on Doctrine's
configured default connection. Tenant rows share the resulting schema; normal
Doctrine filters and RLS remain responsible for data isolation.

The migration connection is recreated from the selected default connection's
public parameters and a clone of its DBAL configuration, then closed after the
command. Auto-commit, result-cache, middleware, and schema-manager settings are
therefore preserved. Only DBAL's schema-assets filter is replaced with an
accept-all filter: DoctrineMigrationsBundle 3.4 deliberately hides the metadata
table from non-Doctrine commands through that filter, which would make an
idempotent second `tenant:migrate` attempt recreate an existing table. This is
a schema-introspection filter only; the bundle's tenant ORM filter and
fail-closed tenant context are not disabled.

### Multiple databases

With `multi_db`, an explicit tenant migrates only its own connection. Without
an explicit tenant, all registered tenants are processed in stable slug/ID
order. Each tenant receives a fresh DBAL connection and DependencyFactory, so
its migration plan and metadata are calculated against that tenant's database.
The connection is closed and the tenant context is cleared after every tenant,
including on exceptions.

Processing stops at the first tenant failure, returns a non-zero Console exit
code, and does not report overall success. The original migration or connection
exception remains the reported failure even if cleanup also fails.

## Doctrine integration

For every selected connection the command uses the programmatic contract
exposed by Doctrine's `DependencyFactory`:

1. resolve the `latest` alias with `getVersionAliasResolver()`;
2. calculate a `MigrationPlanList` with `getMigrationPlanCalculator()`;
3. build a `MigratorConfiguration` carrying dry-run and the configured
   `all_or_nothing` value;
4. pass both objects to the migrator returned by `getMigrator()`;
5. initialize metadata storage through `getMetadataStorage()` only for a real
   execution.

This contract is common to the audited core 3.4.2 source and the installable,
tested core 3.7.4 and 3.9.7 graphs. The bundle does not inspect installed
version strings, reflect on method signatures, or catch `TypeError` to select
a code path.

For `multi_db`, the consumer's migrations paths/classes, metadata table
configuration, organization, template, transactional generation preference,
platform check, and `all_or_nothing` value are copied to a fresh configuration.
Configured connection/entity-manager names are intentionally not copied: the
tenant's explicit DBAL connection is authoritative.

## Dry-run and transactions

Dry-run calculates the same pending plan and renders the SQL returned by
Doctrine. It does not create or alter application tables, initialize the
metadata table, record versions, or leave an open transaction. This is verified
against real PostgreSQL, including the case where metadata storage does not yet
exist.

Normal execution initializes/validates metadata on the selected connection and
delegates migration transactions, migration-level `isTransactional()` choices,
events, and `all_or_nothing` enforcement to Doctrine. The bundle does not add a
transaction around tenants and does not silently alter the consumer's
transaction policy. A successful tenant does not cause a later failed tenant
to be rolled back.

## Rollback and operational use

`tenant:migrate` is an upward-to-latest command. Use Doctrine's ordinary
migration commands on an explicitly selected and verified connection for
rollback or version administration; the bundle does not offer tenant rollback
commands. Test both directions in staging and take a database backup before a
production migration.

For deployment automation:

```bash
php bin/console tenant:migrate --dry-run --no-interaction
php bin/console tenant:migrate --no-interaction
```

Treat a non-zero exit code as a failed deployment. Do not continue with
unscoped schema or data operations after an unknown tenant, provider failure,
connection failure, or migration exception.

## Distribution proofs

The contributor suite runs real PostgreSQL assertions for schema effects,
metadata, two ordered migrations, idempotence, dry-run immutability,
multi-tenant A/B/A isolation, unknown tenants, connection failures, migration
exceptions, context cleanup, and closed tenant connections.

The external Consumer App executes the same real-command recipe on the exact
DoctrineMigrationsBundle 3.4, 3.7, and 4.0.1 graphs. A separate required job
installs a Git archive of the candidate in a fresh consumer through Composer,
compiles a production kernel, and repeats normal and dry-run execution on the
Bundle 3.4/core 3.7 and Bundle 4.0.1/core 3.9 graphs without a path repository
to the checkout.
