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
| 8.5.9 | 8.1 | 3.3 | 3.6 | 4.4 | Services Locaux reference graph; PostgreSQL 16 and 18 |

Symfony 8 is not tested on PHP 8.3 because Symfony 8 requires PHP 8.4 or later. Symfony 8.0 remains in the matrix as the lower bound of the supported `^8.0` constraint even though its normal support window has ended; Symfony 8.1 on PHP 8.5 is the required current Symfony 8 combination. DoctrineBundle 2.19 preserves the PHP 8.3 path, while DoctrineBundle 3.3 provides the Symfony 8-compatible path on PHP 8.4 and later. Doctrine DBAL 3 support is exercised with the oldest supported ORM line, while DBAL 4 is exercised with the current ORM line.

The lowest cell resolves runtime packages with `--prefer-lowest`, then updates PHPStan and its extensions so current static-analysis rules evaluate that runtime graph.

Each matrix cell runs strict Composer validation, a dependency security audit, PHPStan at maximum level, the PHPUnit suite, and the PostgreSQL 18 RLS group. Security advisories fail the audit. Abandoned transitive packages are reported because the lowest-supported dependency graph can contain upstream packages that Composer marks as abandoned. Coding style is a separate required job.

The Symfony 7.4, 8.0, and 8.1 consumer cells also compile both Messenger routing strategies. They prove real bus-to-transport routing for `framework.messenger.routing`, `#[AsMessage]`, configured-route precedence, explicit `TransportNamesStamp` precedence, and synchronous handling when native routing has no sender. The fail-closed tenant/global and persistent-worker contracts are unchanged.

Those three Symfony cells also install their aligned Scheduler and Doctrine
Messenger components. A real `SchedulerTransport` and Worker redispatch a
classified occurrence through a serialized Doctrine transport before a second
Worker invokes the application handler. The Scheduler Worker is asserted not
to invoke that handler. The same tests cover the public `RedispatchMessage`,
`ScheduledStamp`, `ReceivedStamp`, `TransportNamesStamp`, `RecurringMessage`,
`MessageGenerator`, `SendMessageMiddleware`, and redispatch-handler contract
shared by Symfony 7.4, 8.0, and 8.1.

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
Messenger 8.1.5, Scheduler 8.1.5, ORM 3.6.8, and DoctrineBundle 3.3.1. Real
PostgreSQL behavior tests cover the command's multi-database and failure paths,
and the candidate-archive jobs repeat the shared-database command from a ZIP
installed without a path repository.

## Version policy

- PHP versions are supported while they receive upstream security fixes and remain compatible with a supported Symfony branch.
- Symfony 7.4 LTS and Symfony 8.1 are the actively supported framework branches. Symfony 8.0 remains verified as the lower compatibility bound of the `^8.0` constraint.
- Doctrine ORM 3.5 and later within the 3.x line are supported.
- Doctrine DBAL 3.8 and the 4.x line are supported through explicitly tested combinations.
- DoctrineMigrationsBundle 3.4, 3.7, and 4.0.1 are explicitly tested with the real `tenant:migrate` command; migration-core 3.7.4 and 3.9.7 are the pinned resolvable proof points.
- PostgreSQL >= 16 is supported. Required RLS, multi-database, migration, and
  Scheduler persistence recipes run on PostgreSQL 16 and 18 and use no
  PostgreSQL 18-only feature.
- The enabled Symfony cache decorator is compiled and exercised against aligned FrameworkBundle and Cache components on Symfony 7.4, 8.0, and 8.1. It preserves PSR-6, `CacheInterface`, `NamespacedPoolInterface`, and `AdapterInterface` for decorated Symfony pools.
- The persistent-lifecycle Consumer App runs the real Symfony services resetter, an initialized cache, a no-reboot kernel, early resolution, disabled automatic resolution, explicit late resolution, and a dedicated SecurityBundle/lazy-firewall scenario. SecurityBundle remains absent from the bundle's required dependency graph.
- The optional PSR-16 decorator requires `psr/simple-cache` 3.x because earlier interface versions do not define the typed PSR-16 signatures implemented by the bundle.

Removing a matrix entry is a compatibility change. It requires evidence that the combination is no longer resolvable or supportable, an updated changelog, and migration guidance where applicable.

## RC11 optional object storage matrix

The core and historical file API remain installable without Flysystem, the S3
adapter or SDK. Production dependency constraints are unchanged from RC10.
The optional bridge adds these exact dependency graphs:

| Graph | Flysystem | S3 adapter | SDK |
|---|---|---|---|
| Lower | 3.30.2 | 3.30.1 | 3.371.5 |
| Upper | 3.36.0 | 3.35.3 | 3.394.9 |

Both graphs run real MinIO proofs on PHP 8.3 / Symfony 7.4, PHP 8.4 / Symfony
7.4 and 8.0, and PHP 8.5.9 / Symfony 7.4, 8.0 and 8.1. Three separate external
consumer cells compile Symfony 7.4, 8.0 and 8.1 with the integration disabled
without optional packages, then explicitly enabled with them. A Flysystem-only
cell checks that the S3 adapter and SDK remain optional too.

The existing Messenger, Scheduler, cache, Doctrine, migrations, RLS, multi-base
and exact consumer graph remain required. PostgreSQL 16 and 18 are validation
targets; RC11 introduces no SQL and does not require PostgreSQL 18 in production.
See the [migration guide](migration-rc10-to-rc11.md) and
[real MinIO recipe](object-storage-flysystem.md#disposable-minio-test-kit).

## RC12 optional audit matrix

RC12 preserves RC11 bounds and adds audit observations to the same 12 low/high
MinIO HTTPS combinations above. Coverage includes shared/dedicated buckets, active
and historical generations, identity present/absent/invalid/foreign, pagination,
individual anomalies, global page errors, signing, streams and credential rotation.
Critical object-storage and MinIO suites reject skips. Compiled kernels prove
lazy inventory without S3 connection variables, A/B/A and Symfony/bundle resets.

All 22 Compatibility and 16 Object storage jobs must pass on the candidate and
post-promotion main. Minimal, Flysystem-only, archive and public consumers supplement
the source matrix; a source checkout alone is not a distribution proof. The exact
graph and persistent Messenger/Scheduler/PostgreSQL 16/18 recipes remain required.
Keep locks and actual runtime patch versions with release evidence. No mandatory
dependency or SQL migration is added. See [RC12 migration](migration-rc11-to-rc12.md).

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

## RC10 Messenger integration contract

The internal composition pass uses public DependencyInjection definitions,
references, inherited definition metadata and `IteratorArgument`, together with
the `messenger.bus` tag and `MessageBus`'s iterable constructor. It runs after
`MessengerPass` at before-optimization priority -100; it does not use the
intermediate `<bus>.middleware` parameter or private Symfony methods.

The compiled-container suite checks Symfony 7.4/8.0/8.1 with implicit buses,
validation, multiple application middleware and bus chains, default-free
configuration, split YAML, explicit bundle middleware, disabled integration,
profiler, repeat composition and a second boot of the same dumped container.
Behavior checks cover tenant-aware validation, sending, nested and deferred
dispatch, exception cleanup, retries, failure replay and available Symfony 8.1
redecoding. The Consumer App additionally exercises real serialized Doctrine
Scheduler redispatch with application validation and middleware on PostgreSQL
16 and 18. See the [composition audit](audit-rc9-messenger-composition.md).

Messenger remains a required runtime dependency for RC9 compatibility. The
minimal production gate installs it and explicitly disables the integration;
it verifies that no bundle Messenger services are registered. Optional Mailer,
Twig, Monolog, PSR-16 and Scheduler components remain absent from that gate.
