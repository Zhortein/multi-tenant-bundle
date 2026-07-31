# Cross-Repository Roadmap Proposal — July 2026

## Principles and recommended sequence

This roadmap follows the joint audit and deduplication of all existing issues, pull requests, releases, and milestones. Bundle issue #5 was the only pre-existing issue; it concerns maintenance intentions and remains open without any comment or modification.

Recommended sequence:

1. Reproducibility and supported version matrix.
2. Public API, canonical configuration, and dependency boundaries.
3. Security, isolation, and long-running process behavior.
4. Demo application as an automated consumer test.
5. Verified documentation and stable 1.0 release preparation.

Future Services Locaux requirements are treated only as motivating use cases for generic extension points. The bundle must remain public, product-independent, and reusable.

## GitHub milestones

### Bundle

1. **Reproducibility and Supported Baseline**
2. **Public API and Compatibility**
3. **Tenant Isolation and Integrations**
4. **Verified Documentation and 1.0**

### Demo application

1. **Reproducible Integration Baseline**
2. **Automated Bundle Validation**

## Planned issues

### `Zhortein/multi-tenant-bundle`

- Execute a PHP 8.3–8.5, Symfony 7.4/8, and Doctrine DBAL/ORM CI matrix.
- Minimize and classify production dependencies for a reusable Symfony bundle.
- Validate installation in a minimal Symfony application.
- Stabilize configuration and provide deprecation paths for legacy shapes.
- Define the shared-database/native SQL/RLS threat model and guarantees.
- Prove multi-database isolation in long-running processes.
- Stabilize Mailer, Messenger, cache, storage, and observability contracts.
- Package the test kit as a consumable API.
- Align documentation, examples, changelog, tags, and the 1.0 release process.

### `Zhortein/multi-tenant-demo`

- Align PHP, Symfony, Doctrine, and the bundle reference.
- Make Docker and Composer installation immutable and safe.
- Enable migrations, schema validation, and PHPUnit in CI.
- Add authentication, authorization, and deterministic fixtures.
- Prove end-to-end cross-tenant isolation.
- Validate Mailer, Messenger, storage, and cache while aligning documentation with implemented journeys.

## Required issue structure

Every created issue includes verified context, current behavior, expected outcome, acceptance criteria, compatibility risks, expected tests, expected documentation, dependencies or blockers, proposed priority, and qualitative estimate.

## Verified GitHub links

### Bundle milestones

- [1. Reproducibility and Supported Baseline](https://github.com/Zhortein/multi-tenant-bundle/milestone/1)
- [2. Public API and Compatibility](https://github.com/Zhortein/multi-tenant-bundle/milestone/4)
- [3. Tenant Isolation and Integrations](https://github.com/Zhortein/multi-tenant-bundle/milestone/3)
- [4. Verified Documentation and 1.0](https://github.com/Zhortein/multi-tenant-bundle/milestone/2)

### Bundle issues

- [#6 — PHP, Symfony, and Doctrine compatibility matrix](https://github.com/Zhortein/multi-tenant-bundle/issues/6)
- [#7 — Production dependency boundaries](https://github.com/Zhortein/multi-tenant-bundle/issues/7)
- [#8 — Minimal Symfony application installation](https://github.com/Zhortein/multi-tenant-bundle/issues/8)
- [#9 — Public configuration and deprecations](https://github.com/Zhortein/multi-tenant-bundle/issues/9)
- [#10 — Consumable test kit](https://github.com/Zhortein/multi-tenant-bundle/issues/10)
- [#11 — Multi-database isolation in long-running processes](https://github.com/Zhortein/multi-tenant-bundle/issues/11)
- [#12 — Tenant-aware integration contracts](https://github.com/Zhortein/multi-tenant-bundle/issues/12)
- [#13 — Documentation and 1.0 release process](https://github.com/Zhortein/multi-tenant-bundle/issues/13)
- [#14 — Shared-database and RLS threat model](https://github.com/Zhortein/multi-tenant-bundle/issues/14)

### Demo milestones

- [1. Reproducible Integration Baseline](https://github.com/Zhortein/multi-tenant-demo/milestone/1)
- [2. Automated Bundle Validation](https://github.com/Zhortein/multi-tenant-demo/milestone/2)

### Demo issues

- [#2 — Mailer, Messenger, storage, and cache validation](https://github.com/Zhortein/multi-tenant-demo/issues/2)
- [#3 — PHP, Symfony, Doctrine, and bundle alignment](https://github.com/Zhortein/multi-tenant-demo/issues/3)
- [#4 — Safe Docker and Composer installation](https://github.com/Zhortein/multi-tenant-demo/issues/4)
- [#5 — CI migrations, schema validation, and PHPUnit](https://github.com/Zhortein/multi-tenant-demo/issues/5)
- [#6 — End-to-end cross-tenant isolation](https://github.com/Zhortein/multi-tenant-demo/issues/6)
- [#7 — Authentication, authorization, and fixtures](https://github.com/Zhortein/multi-tenant-demo/issues/7)

All milestone associations were verified after creation. Bundle [issue #5](https://github.com/Zhortein/multi-tenant-bundle/issues/5) remains open without any comment or modification.
