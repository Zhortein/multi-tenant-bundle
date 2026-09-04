# Compatibility Policy

## Supported runtime matrix

Every supported combination is resolved from `composer.json` and exercised in GitHub Actions. The matrix deliberately lists valid combinations instead of taking a Cartesian product.

| PHP | Symfony | DoctrineBundle | Doctrine ORM | Doctrine DBAL | Dependency mode |
|---|---|---|---|---|---|
| 8.3 | 7.4 | 2.19 | 3.5 | 3.8 | Lowest supported runtime versions |
| 8.3 | 7.4 | 2.19 | 3.6 | 4.4 | Latest supported versions |
| 8.4 | 7.4 | 3.3 | 3.6 | 4.4 | Latest supported versions |
| 8.4 | 8.0 | 3.3 | 3.6 | 4.4 | Latest supported versions |
| 8.5 | 7.4 | 3.3 | 3.6 | 4.4 | Latest supported versions |
| 8.5 | 8.0 | 3.3 | 3.6 | 4.4 | Latest supported versions |
| 8.5.9 | 8.1 | 3.3 | 3.6 | 4.4 | RC4 reference consumer graph; PostgreSQL 18 |

Symfony 8 is not tested on PHP 8.3 because Symfony 8 requires PHP 8.4 or later. Symfony 8.0 remains in the matrix as the lower bound of the supported `^8.0` constraint even though its normal support window has ended; Symfony 8.1 on PHP 8.5 is the required current Symfony 8 combination. DoctrineBundle 2.19 preserves the PHP 8.3 path, while DoctrineBundle 3.3 provides the Symfony 8-compatible path on PHP 8.4 and later. Doctrine DBAL 3 support is exercised with the oldest supported ORM line, while DBAL 4 is exercised with the current ORM line.

The lowest cell resolves runtime packages with `--prefer-lowest`, then updates PHPStan and its extensions so current static-analysis rules evaluate that runtime graph.

Each matrix cell runs strict Composer validation, a dependency security audit, PHPStan at maximum level, the PHPUnit suite, and the PostgreSQL 18 RLS group. Security advisories fail the audit. Abandoned transitive packages are reported because the lowest-supported dependency graph can contain upstream packages that Composer marks as abandoned. Coding style is a separate required job.

The Symfony 7.4, 8.0, and 8.1 consumer cells also compile both Messenger routing strategies. They prove real bus-to-transport routing for `framework.messenger.routing`, `#[AsMessage]`, configured-route precedence, explicit `TransportNamesStamp` precedence, and synchronous handling when native routing has no sender. The fail-closed tenant/global and persistent-worker contracts are unchanged.

The migration-command matrix is explicit because DoctrineMigrationsBundle and
Doctrine Migrations core use separate version lines:

| DoctrineMigrationsBundle | Doctrine Migrations core | DoctrineBundle | DBAL | PHP | PostgreSQL | Required command proof |
|---|---|---|---|---|---|---|
| 3.4.0 | 3.7.4 | 2.19.0 | 3.8.7 | 8.3 | 16 and 18 | dry-run, migrate, idempotence |
| 3.7.0 | 3.9.7 | 3.3.1 | 4.4.4 | 8.4 | 16 and 18 | dry-run, migrate, idempotence |
| 4.0.1 | 3.9.7 | 3.3.1 | 4.4.4 | 8.5.9 | 16 and 18 | dry-run, migrate, idempotence |

DoctrineMigrationsBundle 4.0.1 depends on the 3.x migration core; it is not a
`doctrine/migrations` 4.0.1 release. Core 3.4.x itself cannot be combined with
this bundle's Symfony 7.4 floor because its Symfony Console and Stopwatch
constraints end at Symfony 6; core 3.7.4 is the oldest pinned migration-engine
proof. The exact Bundle 4 consumer graph also uses PHP 8.5.9, Symfony 8.1.5,
ORM 3.6.8, and DoctrineBundle 3.3.1. Real
PostgreSQL behavior tests cover the command's multi-database and failure paths,
and the candidate-archive jobs repeat the shared-database command from a ZIP
installed without a path repository.

## Version policy

- PHP versions are supported while they receive upstream security fixes and remain compatible with a supported Symfony branch.
- Symfony 7.4 LTS and Symfony 8.1 are the actively supported framework branches. Symfony 8.0 remains verified as the lower compatibility bound of the `^8.0` constraint.
- Doctrine ORM 3.5 and later within the 3.x line are supported.
- Doctrine DBAL 3.8 and the 4.x line are supported through explicitly tested combinations.
- DoctrineMigrationsBundle 3.4, 3.7, and 4.0.1 are explicitly tested with the real `tenant:migrate` command; migration-core 3.7.4 and 3.9.7 are the pinned resolvable proof points.
- PostgreSQL 18 is the reference database for RLS defense-in-depth guarantees.
- The enabled Symfony cache decorator is compiled and exercised against aligned FrameworkBundle and Cache components on Symfony 7.4, 8.0, and 8.1. It preserves PSR-6, `CacheInterface`, `NamespacedPoolInterface`, and `AdapterInterface` for decorated Symfony pools.
- The persistent-lifecycle Consumer App runs the real Symfony services resetter, an initialized cache, a no-reboot kernel, early resolution, disabled automatic resolution, explicit late resolution, and a dedicated SecurityBundle/lazy-firewall scenario. SecurityBundle remains absent from the bundle's required dependency graph.
- The optional PSR-16 decorator requires `psr/simple-cache` 3.x because earlier interface versions do not define the typed PSR-16 signatures implemented by the bundle.

Removing a matrix entry is a compatibility change. It requires evidence that the combination is no longer resolvable or supportable, an updated changelog, and migration guidance where applicable.

## Local validation

The default local environment validates with PHP 8.5.9. Cross-version support remains an explicit CI matrix rather than an accidentally mixed dependency graph.

```bash
make composer-validate
make phpstan
make csfixer-check
make test
make test-with-postgres
make test-tenant-migrate
```

The GitHub Actions matrix is authoritative for cross-version support because the bundle intentionally does not commit a Composer lock file.
PostgreSQL `>= 16` is supported. CI targets PostgreSQL 16 and PostgreSQL 18;
PostgreSQL 17 may be exercised as an additional matrix entry. RLS remains optional
defense in depth and uses no PostgreSQL 18-specific syntax.
