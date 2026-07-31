# Compatibility Policy

## Supported runtime matrix

Every supported combination is resolved from `composer.json` and exercised in GitHub Actions. The matrix deliberately lists valid combinations instead of taking a Cartesian product.

| PHP | Symfony | Doctrine ORM | Doctrine DBAL | Dependency mode |
|---|---|---|---|---|
| 8.3 | 7.4 | 3.5 | 3.8 | Lowest supported versions |
| 8.3 | 7.4 | 3.6 | 4.4 | Latest supported versions |
| 8.4 | 7.4 | 3.6 | 4.4 | Latest supported versions |
| 8.4 | 8.0 | 3.6 | 4.4 | Latest supported versions |
| 8.5 | 7.4 | 3.6 | 4.4 | Latest supported versions |
| 8.5 | 8.0 | 3.6 | 4.4 | Latest supported versions |

Symfony 8 is not tested on PHP 8.3 because Symfony 8 requires PHP 8.4 or later. Doctrine DBAL 3 support is exercised with the oldest supported ORM line, while DBAL 4 is exercised with the current ORM line.

Each matrix cell runs strict Composer validation, a dependency security audit, PHPStan at maximum level, the PHPUnit suite, and the PostgreSQL 16 RLS group. Coding style is a separate required job on PHP 8.3.

## Version policy

- PHP versions are supported while they receive upstream security fixes and remain compatible with a supported Symfony branch.
- Symfony 7.4 and 8.0 are the supported framework branches.
- Doctrine ORM 3.5 and later within the 3.x line are supported.
- Doctrine DBAL 3.8 and the 4.x line are supported through explicitly tested combinations.
- PostgreSQL 16 is the reference database for RLS guarantees.

Removing a matrix entry is a compatibility change. It requires evidence that the combination is no longer resolvable or supportable, an updated changelog, and migration guidance where applicable.

## Local validation

The default local environment validates the latest PHP 8.3-compatible dependency set:

```bash
make composer-validate
make phpstan
make csfixer-check
make test
make test-with-postgres
```

The GitHub Actions matrix is authoritative for cross-version support because the bundle intentionally does not commit a Composer lock file.
