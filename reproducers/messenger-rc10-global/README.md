# Public RC10 global dispatch reproduction

This test intentionally asserts the defect in the public
`zhortein/multi-tenant-bundle:1.0.0-rc.10` distribution. It is separate from the
permanent candidate regression tests, which assert the corrected contract.
Never run this historical expectation against the candidate package.

## Provenance and commands

Verified on 2026-09-05: Packagist's source and ZIP distribution references,
`v1.0.0-rc.10^{}`, and public `main` all identify
`57856fc60579c8ece035b3160aa2c213e642a834`. The installed Composer package
reference matched that commit; all 141 installed PHP source files were byte-identical
to the public archive. No local repository override was used.

Run from the bundle root with the documented Consumer App Docker image (PHP
8.5.9, Composer, PDO SQLite; `tests/ConsumerApp/Dockerfile` also supports PDO
PostgreSQL). The image must already be built as `mtb-consumer-proof`:

```sh
proof_dir=$(mktemp -d /tmp/mtb-rc10-global.XXXXXX)
git archive 57856fc60579c8ece035b3160aa2c213e642a834 tests/ConsumerApp | tar -x -C "$proof_dir"
docker run --rm -v "$PWD/reproducers/messenger-rc10-global:/reproducer:ro" -v "$proof_dir/tests/ConsumerApp:/consumer" -w /consumer mtb-consumer-proof php /reproducer/prepare.php /consumer
docker run --rm -v "$proof_dir/tests/ConsumerApp:/consumer" -w /consumer mtb-consumer-proof composer update --prefer-dist --no-progress --no-interaction
docker run --rm --network none -v "$proof_dir/tests/ConsumerApp:/consumer" -w /consumer mtb-consumer-proof vendor/bin/phpunit tests/Rc10GlobalDispatchReproductionTest.php --no-coverage
```

The dependency resolution is deliberately external to the repository. Preserve
its lock file with local evidence if repeating the exact dependency graph;
do not commit the archive, vendor tree, or generated container.

## Observed result before correction

PHP 8.5.9; FrameworkBundle and Messenger 8.1.6; Scheduler 8.1.5;
PHPUnit 12.5.34: **2 tests, 20 assertions, successful defect reproduction**.

1. `ScheduledGlobalMessage` implements only `GlobalMessageInterface`.
2. A real compiled default bus retains the bundle guards, Validator, and the
   consumer middleware. The context contains tenant A (`1`).
3. The application directly dispatches the global payload to the Doctrine
   transport using an explicit public `TransportNamesStamp`. There is **no
   `RedispatchMessage` wrapper in this direct reproduction**.
4. `TenantSendingMiddleware` uses `MessageClassification::fromEnvelope()` and
   correctly leaves the global payload unstamped. The following
   `TenantMessengerTransportResolver` checks only the active tenant and adds
   `TenantStamp('1')`. The returned envelope is now contradictory.
5. The Doctrine transport really serializes and persists one SQLite row. No
   business handler has run.
6. A real Messenger `Worker` receives/deserializes the row.
   `TenantWorkerMiddleware` clears the previous tenant and calls the same
   classification authority, which throws `TenantMismatchException` for the
   global payload carrying a tenant stamp.
7. No business handler runs; the Worker rejects the row, and final context is
   `NONE`. The persistent queue is empty.

The second case dispatches an outgoing `RedispatchMessage` around the same
global-only application payload. RC10 stamps the outer wrapper; Symfony's
public `RedispatchMessageHandler` redispatches the application payload to the
explicit transport, and the resolver incorrectly stamps that outgoing payload
too. The persistent Worker again rejects before the application handler. A
Scheduler-received global wrapper already starts at `NONE`; the existing receive
boundary clears stale context before Symfony redispatches its payload.

The existing recursive authority already unwraps supported Scheduler
`RedispatchMessage` structures and inspects tenant stamps at every level. The
resolver had bypassed that authority. The fix uses it before any tenant-specific
stamp or route is added; no bus composition or classification rule changes.

Permanent tests are in `tests/Messenger/TenantMessengerTransportResolverTest.php`,
`tests/Integration/MessengerCompositionTest.php`, and
`tests/ConsumerApp/tests/GlobalDispatchTest.php`. They cover direct and wrapped
sending under A, A/global/B/global on the same Worker, both compiled buses,
persistent Scheduler-to-Worker paths for both classifications, rejection before
application effects, and cleanup on success and failure. Existing Scheduler
malformed-wrapper, retry, and routing tests remain in force.
