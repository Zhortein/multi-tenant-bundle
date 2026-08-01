# Release process

Published releases and pre-releases are identified only by Git tags and GitHub releases. Changelog sections labelled as historical RC planning describe untagged development work and must not be interpreted as published versions.

## Versioning

The first published contract will use Semantic Versioning. Until a tag is explicitly authorized, all changes remain under `Unreleased`. A breaking pre-1.0 change must include a migration note; after 1.0, public API breaks require a new major version.

## Candidate validation

Before proposing a tag:

1. Freeze the intended public API and move only shipped entries from `Unreleased` into the candidate version.
2. Run Composer validation and audit, PHPStan at maximum level, PHP-CS-Fixer, the complete PHPUnit suite, and effective PostgreSQL RLS tests.
3. Require the full PHP/Symfony/Doctrine matrix, including Symfony 7.4 and Symfony 8.1 on PHP 8.5.
4. Install the candidate through production Composer autoload in the external consumer fixture for `shared_db` and `multi_db`.
5. Point the demo at the exact candidate commit or package version and run its complete functional and tenant-isolation pipeline.
6. Review migration guides, documentation links, configuration examples, and release notes.
7. Obtain separate human authorization before creating a tag, GitHub release, or package publication.

A failed or skipped required isolation check blocks the candidate. Symfony 8.0 remains useful lower-bound coverage while maintained at proportionate cost; it may only be removed through a documented compatibility decision.

## Migration documentation

Every intentional break must state the previous behavior, the new contract, required application or data migration, and rollback considerations. The current security-contract migration is documented in [Security Contract Migration](migration-security-contracts.md).
