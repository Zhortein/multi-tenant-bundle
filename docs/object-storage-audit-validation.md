# Object storage audit capabilities: development validation

Date: 2026-09-13. Repository: `Zhortein/multi-tenant-bundle`.
This is an unreleased upstream capability batch, not an RC12 publication or a
consumer integration. See the [public contract](object-storage-audit.md) and
[future migration procedure](migration-rc11-to-rc12.md).

## Starting point and scope

- Verified GitHub origin: `Zhortein/multi-tenant-bundle`.
- Fetched with pruning of obsolete remote-tracking branches only; no remote
  branch or tag deletion, local history rewrite or worktree pruning.
- Current `origin/develop`: `7da9f219f5f7859933c3f9dc5780602569c36d6b`.
- Current `origin/main` and dereferenced `v1.0.0-rc.11`:
  `fe769e9e2ea6fc5db6bd2f8bd23f2e52ba04ee94`.
- RC11 annotated tag object: `2eb5542dc9ab8ab2b41b060556a9e7649d6ba783`.
- No open PR or merged PR after RC11 promotion at inspection. Local older topic
  branches were inspected; no equivalent audit implementation was found.
- Initial index/worktree clean, no unfinished Git operation. Dedicated branch:
  `feature/object-storage-audit-capabilities`, based on current `origin/develop`.
- The motivating consumer assessment was supplied as text in the task. Its private
  application details and contents are not copied into the public repository.
  Other repositories, their branches and their worktrees are outside this change.

## Implementation and compatibility evidence

The optional interface is implemented by the existing facade. The mandatory
RC11 facade and backend interfaces, `StoredObjectReference`, reference JSON,
`ObjectMetadata`, strict listing page, legacy file storage and `composer.json`
are unchanged. Existing constructors only receive optional trailing arguments.
New registry registrations defer backend construction through public Symfony
service closures. Inventory checks descriptors/allowlists without constructing
clients, invoking bindings or choosing another provider. A compiled external
consumer inventories A/B/A even with all S3 connection variables unset.

New content-plus-identity writes are explicit. Logical claims authenticate under
application keys, retain their original expected-reference fingerprint/generation
through copies, and are bound to a tenant namespace. They do not attest content
or protect against replay by a storage administrator. Historical RC11 objects
without envelopes remain readable and yield `identity_absent`, never fabricated
provenance. Credential rotation does not alter references or logical identity.

An individual malformed in-prefix key, missing/invalid/foreign logical envelope,
disappearance or inaccessible HEAD can be observed without making a foreign entry
operational. A foreign physical key, malformed global page or cursor, changed
tenant/binding/reset fails the page. The page is structurally checked before any
individual HEAD. Cursors are bounded authenticated ciphertext, including when the
last key is malformed; no raw key is returned. Stream copies retain 64 KiB chunks
and the existing ownership/invalidation contract. No SQL, object backfill, repair,
orphan deletion, automatic migration, package publication or release is added.

## Local validation before PR

All PHP/tool commands used project Docker images; no tooling was installed on
the host. The existing dependency installation was preserved. A disposable external
consumer resolved its own lock file and installed the bundle through a path
repository with copying, not symlinking.

| Check | Observed result |
| --- | --- |
| Critical unit/integration object storage tests | 215 tests, 926 assertions; `--fail-on-skipped --fail-on-phpunit-notice`, success, no skips/notices. |
| Real MinIO HTTPS recipe | 11 tests, 259 assertions, success, no skips. Includes 3 new audit tests alongside the existing signing/lifecycle suite. |
| Full PHPUnit / PHP 8.5.9 / PostgreSQL 16 | 871 tests, 3703 assertions, no errors/failures; strict experimental `--fail-on-skipped` returned 1 for 11 preexisting skips. See limitations below. |
| Full PHPUnit / PHP 8.5.9 / PostgreSQL 18 | 871 tests, 3703 assertions, exit 0 under the documented PHPUnit policy; same 11 preexisting skips and notices. |
| PHPStan `level: max` on `src` | Success, no errors. |
| CS Fixer / project Symfony rules | Checked all 409 source/test files; final whitespace correction was limited to the new identity class. |
| Composer validate | Valid, strict mode with the documented `--no-check-lock`. |
| Composer audit / installed graph | No security vulnerability advisories. |
| Documentation/YAML/local links | 58 documents validated before this report was added. |
| Actionlint / ShellCheck | Success with existing pinned tool images. |
| Compose | MinIO configuration validated on each real recipe invocation; no published MinIO ports. |
| External consumer / PHP 8.5.9 | Production compile without Flysystem; legacy bridge compile; audit compile/inventory with missing S3 environment variables and runtime-key cache scan, all success. |
| RC11 unchanged-file comparison | Mandatory interfaces, reference serialization, metadata/strict page, legacy storage and Composer manifest identical to the base. |
| `git diff --check` / targeted secret-pattern scan | Success; no private key/token pattern found in changed source, tests or documentation. Test credentials are disposable fixtures. |

The full local PostgreSQL runs preceded the final identity serialization/cursor
regression test; the subsequent focused suite covers that change. Exact commit
matrix runs must still pass before merge; these local counts are not claims about
a later commit's complete CI run.

### Existing skips and notices

The 11 skips come from unchanged tests: seven repository placeholders in
`TenantSettingRepositoryTest`, two resolver placeholders in
`TenantContextIntegrationTest`, and two disabled historical storage service cases
in `DecoratorsTest`. No new test is skipped and all critical object-storage tests
run with skip rejection. The PostgreSQL-backed lifecycle, Messenger, Scheduler
and RLS suites execute in the full runs. Their success does not execute these
11 placeholders; that existing coverage limitation remains explicit.

The installed PHPUnit 12.5.33 reports 641 notices across 295 existing tests about
mock objects with no configured expectations. No suppression or baseline change
was added. CS Fixer notes the local PHP 8.5.9 runtime versus the project's PHP 8.3
minimum; CI also checks style on PHP 8.3. Composer in the basic PHP container cannot
infer the root Git version and emits its existing `1.0.0` fallback notice.

Initial GitHub DNS access required the authorized network escalation. The first
multi-path fixer invocation required an explicit config path; the first ShellCheck
container invocation required an explicit executable. Both commands were corrected.
No failure is counted as a successful check.

## Exact-SHA delivery gates

PR: [#68](https://github.com/Zhortein/multi-tenant-bundle/pull/68).
Initial implementation commit: `d9548f66272b91e386405aff05f6636d3f566199`.
The adversarial review found exception-argument retention when PHP is configured
with `zend.exception_ignore_args=0`. Two failing regression tests demonstrated
retained backend closure arguments and an invalid key in a validation frame.
The follow-up discards backend traces at the facade boundary and marks captured
operations, keys, tokens and envelope payloads as sensitive parameters. Public
reason/outcome semantics remain unchanged. Tests inspect bundle-owned frames;
arbitrary caller stack arguments are outside the bundle's control.

The first CI runs on that commit exposed an existing strict-listing null comparison
as redundant under the newly resolved PHPStan version. All 215 critical tests passed
there, but 19 matrix jobs stopped at static analysis. The follow-up expresses the
empty-page branch explicitly, preserving the RC11 result and removing no validation.
No PHPStan suppression or dependency pin was introduced to bypass the failure.
Initial run records:
[Compatibility](https://github.com/Zhortein/multi-tenant-bundle/actions/runs/34745904671),
[Object storage](https://github.com/Zhortein/multi-tenant-bundle/actions/runs/34745904666).

The implementation PR targets `develop`. Before merge, require the complete
Compatibility and Object storage workflows, the exact head review, unchanged
`main`/RC11, and no concurrent equivalent PR. The workflows cover supported PHP
8.3/8.4/8.5, Symfony 7.4/8.0/8.1, PostgreSQL 16/18, lowest/highest and exact consumer
graphs, minimal production, Flysystem without S3, external consumers and candidate
archives. Object storage adds 12 low/high Flysystem/S3/SDK runtime combinations
with real MinIO and keeps critical skip rejection enabled.

Record exact commit review and run links in the PR conversation, and verify both
workflows again on the exact merge commit. This document is evidence of local
development, not an assertion that unexecuted remote gates passed.

## Resources and exclusions

MinIO recipes create uniquely named containers/networks and temporary certificate
directories, then remove only those resources. Local PostgreSQL runs use unique
`mtb-audit-qa-pg*` networks, ephemeral databases on tmpfs, no published port and the
repository's SQL fixtures; their containers/networks are removed by the runner.
Preexisting containers, images, volumes, networks and worktree registrations are
preserved. Ignored PHPUnit, PHPStan and CS Fixer caches may be updated.

The external-consumer checkout and logs are temporary validation resources; remove
only this batch's directory after extracting evidence. No application repository,
database or object provider is used. No RC12 tag, release or package is published.

## File inventory

The PR diff is the authoritative complete tracked-file inventory. It consists of
the optional public audit contracts/DTOs and codec, registry/facade/bridge additions,
their unit/compiled-kernel/MinIO/consumer tests, the object storage workflow,
configuration reference, changelog and audit/migration documentation. The final
review records the exact commit and verifies this scope against the original base.

```text
.github/workflows/object-storage.yml
CHANGELOG.md
config/reference.php
docs/configuration.md
docs/index.md
docs/migration-rc11-to-rc12.md
docs/object-storage-audit-validation.md
docs/object-storage-audit.md
docs/object-storage.md
src/DependencyInjection/ObjectStorageConfiguration.php
src/ObjectStorage/AuditListingBackendInterface.php
src/ObjectStorage/BackendIdentityObservation.php
src/ObjectStorage/Bridge/Flysystem/AuditableFlysystemBackend.php
src/ObjectStorage/Bridge/Flysystem/FlysystemBackend.php
src/ObjectStorage/Bridge/Flysystem/S3Capabilities.php
src/ObjectStorage/Bridge/Flysystem/S3CompatibleStorageFactory.php
src/ObjectStorage/Internal/ObjectStorageAuditOperations.php
src/ObjectStorage/LogicalObjectIdentity.php
src/ObjectStorage/ObjectAuditPage.php
src/ObjectStorage/ObjectIdentityBackendInterface.php
src/ObjectStorage/ObjectIdentityObserverInterface.php
src/ObjectStorage/ObjectObservation.php
src/ObjectStorage/ObjectObservationState.php
src/ObjectStorage/ObjectStorageAuditCodec.php
src/ObjectStorage/ObjectStorageRegistry.php
src/ObjectStorage/StorageAuditScope.php
src/ObjectStorage/StorageLocationDescriptor.php
src/ObjectStorage/StorageLocationInventoryPage.php
src/ObjectStorage/StorageLocationRegistration.php
src/ObjectStorage/TenantObjectStorage.php
src/ObjectStorage/TenantObjectStorageAuditInterface.php
tests/Fixtures/ObjectStorage/AuditBackend.php
tests/Fixtures/ObjectStorage/AuditCodecFactory.php
tests/Fixtures/ObjectStorage/ObjectStorageKernel.php
tests/Integration/ObjectStorage/ObjectStorageAuditLifecycleTest.php
tests/ObjectStorage/Consumer/bin/validate.php
tests/ObjectStorage/Consumer/src/Kernel.php
tests/ObjectStorage/Minio/MinioAuditTest.php
tests/Unit/ObjectStorage/ObjectStorageAuditTest.php
tests/Unit/ObjectStorage/ObjectStorageConfigurationTest.php
tests/Unit/ObjectStorage/S3AuditCapabilitiesTest.php
```
