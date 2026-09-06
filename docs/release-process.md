# Release process

Published releases and pre-releases are identified only by Git tags and GitHub releases. Changelog sections labelled as historical RC planning describe untagged development work and must not be interpreted as published versions.

## Versioning

The first published contract will use Semantic Versioning. Until a tag is explicitly authorized, all changes remain under `Unreleased`. A breaking pre-1.0 change must include a migration note; after 1.0, public API breaks require a new major version.

## Candidate validation

Before proposing a tag:

1. Freeze the intended public API and move only shipped entries from `Unreleased` into the candidate version.
2. Run Composer validation and audit, PHPStan at maximum level, PHP-CS-Fixer, the complete PHPUnit suite, and effective PostgreSQL RLS tests.
3. Require the full PHP/Symfony/Doctrine matrix, including Symfony 7.4 and Symfony 8.1 on PHP 8.5.
4. Build a Git archive from the exact candidate commit, reject generated or VCS directories, and install that ZIP through a temporary Composer package repository in a fresh consumer. Do not use a path repository, working-tree branch, or invented release version for this distribution proof.
5. Compile the archive consumer's production kernel, prove
   `SchedulerTransport` redispatch through a persistent transport without
   business handling in the Scheduler Worker, and execute real
   `tenant:migrate --dry-run` and `tenant:migrate` checks against the required
   Doctrine Migrations and PostgreSQL graphs.
6. Install the candidate through production Composer autoload in the external consumer fixture for `shared_db` and `multi_db`.
7. Verify Symfony 7.4, 8.0, and 8.1, the exact reference-consumer graph, and
   PostgreSQL 16 and 18 before publishing. Update the demo only after the
   candidate is publicly available and independently installable from
   Packagist.
8. Review migration guides, documentation links, configuration examples, and release notes.
9. Confirm explicit human authorization covers the tag, GitHub release and package publication. An existing release-mission authorization is sufficient within its stated scope; never bypass branch protection.

A failed or skipped required isolation check blocks the candidate. Symfony 8.0 remains useful lower-bound coverage while maintained at proportionate cost; it may only be removed through a documented compatibility decision.

## Prerelease publication

Promote a green `develop` through the normal protected pull-request path to
`main`; direct pushes and protection bypasses are forbidden. After post-merge
`main` CI succeeds, create one annotated prerelease tag on that exact commit and
publish a public, non-draft GitHub prerelease. Never move a published tag.

Packagist must then expose both source and dist for the same commit. Validate
the license, advisory status, and a fresh installation that has no path, VCS,
fork, or invented-version repository. Repeat the persistent Scheduler proof on
the public package before updating downstream demonstration applications.

## RC11 distribution gate

Audit the complete RC10-to-candidate diff, including the global Messenger fix,
object storage core and optional bridge. Documentation preparation targets
`develop`; any functional or security correction requires its own validated PR
before promotion. Preserve an outstanding published `main` merge in `develop`
through a normal synchronization merge and PR, without rewriting or copying
commits. Wait for CI on the terminal `develop` commit.

Build the candidate ZIP from that exact SHA. Fresh consumers must install it
through Composer's explicit `dev-candidate` package repository, with source/dist
references recorded; path repositories and manual copies into `vendor` do not
count as release distribution proofs. Compare the installed files with the
candidate tree. Execute both minimal production compilation without Flysystem
and enabled object storage with explicitly installed optional dependencies and
real pinned MinIO, including isolation, historical generations, streams,
pagination, signing/download/expiry and serialized Messenger references.

Require all 38 compatibility/object-storage checks, including the 12 protected
contexts, and the documented PHP/Symfony/Doctrine/PostgreSQL matrices. No critical
MinIO or isolation scenario may be skipped. Record historical PHPUnit notices
and unrelated fixture skips separately. Ordinary external-consumer path jobs
alone do not satisfy the candidate or public distribution gate.

Promote through a normal non-draft PR with no unresolved discussion or blocking
review, then wait for all post-merge `main` checks on the exact final commit.
Only afterward create the previously absent annotated `v1.0.0-rc.11` tag, push
only that tag and publish a public non-draft GitHub prerelease. Never move an
earlier tag. Release notes must state that RC11 remains a prerelease and that
upgrading executes no production migration.

Wait reasonably for Packagist indexing. Verify exact version, source/dist SHA,
MIT license, Composer constraints and advisory status, and compare archive bytes
with the tag. Repeat both profiles in new consumers using only
`zhortein/multi-tenant-bundle:1.0.0-rc.11` from Packagist, with no alternative
repository. Include production compilation, audit, PostgreSQL 16/18 persistent
recipes and Symfony compatibility bounds. Preserve evidence of installed
versions and bytes. Downstream applications are separate release lots.

## Migration documentation

Every intentional break must state the previous behavior, the new contract, required application or data migration, and rollback considerations. The current security-contract migration is documented in [Security Contract Migration](migration-security-contracts.md).

## RC10 composition gate

Retain the RC9 red reproducer and the A/B findings. Require the compiled-bus
matrix and the Consumer App's validation/application-middleware/Scheduler proof
on the exact candidate SHA. Review only necessary ordering relations; do not
freeze Symfony's entire middleware order. Record any skipped tests and notices
separately from passed required isolation recipes.

Keep Messenger as a runtime dependency. The minimal production gate must prove
that the component is installed and the integration is explicitly disabled;
an installation without the component is not the RC10 compatibility contract.

After the implementation PR merges to `develop`, wait for post-merge CI, promote
through a normal PR to `main`, and wait for `main` CI before creating the
annotated prerelease tag. Check Packagist source/dist references against that
SHA and repeat a fresh public install with the exact reference graph on
PostgreSQL 16 and 18. Only then update and merge the demo to `develop`; do not
promote the demo to `main` as part of this release.
