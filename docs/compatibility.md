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

## Version policy

- PHP versions are supported while they receive upstream security fixes and remain compatible with a supported Symfony branch.
- Symfony 7.4 LTS and Symfony 8.1 are the actively supported framework branches. Symfony 8.0 remains verified as the lower compatibility bound of the `^8.0` constraint.
- Doctrine ORM 3.5 and later within the 3.x line are supported.
- Doctrine DBAL 3.8 and the 4.x line are supported through explicitly tested combinations.
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
```

The GitHub Actions matrix is authoritative for cross-version support because the bundle intentionally does not commit a Composer lock file.
PostgreSQL `>= 16` is supported. CI targets PostgreSQL 16 and PostgreSQL 18;
PostgreSQL 17 may be exercised as an additional matrix entry. RLS remains optional
defense in depth and uses no PostgreSQL 18-specific syntax.
