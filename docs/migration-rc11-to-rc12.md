# RC11 to RC12: optional object storage audit adoption

**RC12 remains a prerelease, not a stable release.** It publishes the optional
object storage capabilities introduced by PR #68. A consumer that does not use
audit needs no application, configuration, reference or database change.
Validate the exact dependency lock and the consumer's tests before adoption.

## Compatibility and explicit activation

The mandatory `TenantObjectStorageInterface`, its calls and implementers,
`StoredObjectReference` v1, canonical JSON and equality remain unchanged.
Historical references still resolve their exact location and binding. The old
strict `list()` keeps its behavior; tolerant observations use a separate method.
Legacy file storage, Messenger, Scheduler, persistent lifecycle and bus composition
retain the RC11 contract. Upgrading enables no integration.

There is **no SQL migration**, reference conversion, automatic object rewrite,
metadata backfill, repair, deletion or reallocation. Flysystem and S3 remain
optional. Minimal production compilation works without Flysystem; installing
Flysystem does not require the S3 adapter or SDK.

Keep `object_storage.audit.enabled: false` unless adopting audit. To enable it:

1. Enable the existing object storage integration and register an
   `ObjectStorageAuditCodec` service with independent random keys resolved at
   runtime. Never embed secrets in definitions or compiled cache. OpenSSL is
   required only for enabled audit.
2. Preserve immutable location IDs, effective bindings, namespaces and allowlists.
   Associate every historical generation with its registered logical provider.
3. Explicitly select capable backends and declare `audit_listing` and
   `identity_observation`. The S3 factory requires its trailing `audit: true`
   argument and an `AuditableFlysystemBackend` service declaration.
4. Set `audit.enabled: true` and `audit.codec` to the runtime service ID.

See the complete [YAML and PHP examples](object-storage-audit.md#configuration-and-lazy-inventory).
Incomplete service/capability configuration is refused at compilation. Secret
values are validated at runtime. Audit keys are independent of storage credentials;
retain old verification keys during rotation.

## New public API

The existing facade additionally implements `TenantObjectStorageAuditInterface`:

- `inventoryLocations()` returns tenant-filtered pages of active and historical
  `StorageLocationDescriptor` values. `ObjectStorageRegistry::inventory()` accepts
  an explicit tenant. Inventory constructs no backend/client, invokes no binding
  or provider factory, performs no network I/O and allocates no object.
- `auditScope($locationId)` selects one explicit authorized location and returns
  a `StorageAuditScope` without allocating an object. It may construct that
  location's client; it opens no other provider and discovers no unknown target.
- `writeWithIdentity()` and `writeFromStreamWithIdentity()` opt individual new
  writes into atomic content-plus-identity operations.
- `observe()` and `auditList()` return structured `ObjectObservation` values.

Inventory order is stable bytewise location-ID order, with page limits of 1–1000.
Location IDs identify immutable generations; providers choose active allocations;
physical references identify exact addresses; logical identities express a claim
independent of observed placement. Cursors are opaque, bounded and tied to the
query's tenant/scope, registry revision and limit. Object cursors also bind the
selected effective physical target. Never reconstruct a physical key.

## Trust and historical objects

Create a versioned `LogicalObjectIdentity` from an expected reference and a random
opaque correlation ID. Persist the expected identity in the consumer's own model.
`matchesReference()` checks the whole expected reference; `sameLogicalObject()`
compares tenant and correlation ID across placements.

`verified` authenticates a valid same-tenant **application claim** under a retained
application key. It **does not attest content**, business ownership or prior
existence. It **does not protect against reproduction/replay by a storage
administrator**, who can copy an envelope with arbitrary content or remove it.
An application key holder can create claims. See the full
[trust boundary](object-storage-audit.md#trust-boundary).

Objects written before RC12 normally have no identity envelope: **`identity_absent`
is expected**, even when an exact reference matches an application record.
Provenance cannot be reconstructed automatically from content, size, date or
opaque address fragments. RC12's ordinary legacy writes also omit identity.
An unsupported backend reports `unavailable`; neither state invents an identity.

## Individual anomalies and page errors

Missing/invalid identity, a malformed in-prefix reference, an authenticated foreign
claim, disappearance and metadata-read failure have individual sanitized states.
`foreign` and `invalid_reference` expose only an opaque observation ID.
Continuation is possible while the physical tenant boundary remains guaranteed.

A foreign physical key, invalid global structure/order, forged cursor, changed
binding or tenant/reset fails the whole page. No partial page or foreign usable
reference escapes. Sanitized public errors have `getPrevious() === null`.
Retain uncertainty and incomplete-audit status: missing identity or failed I/O
never establishes an orphan verdict. Existing RC11 listings remain strict.

## Progressive adoption and application rollback

First upgrade a disposable consumer with audit disabled and run its RC11 tests.
Then enable inventory and read-only observations in a controlled environment,
preserving every historical generation. Add identity only to explicitly selected
new writes. Test shared/dedicated storage, A/B/A, both reset boundaries, key and
credential rotation, pagination, anomalies and network/TLS failures. Consumer
persistence and business classification remain separate decisions; the bundle
knows no business entity and queries no application database for correlation.

For rollback, stop/drain audit-specific work, disable its calls and service wiring,
and restore RC11-compatible configuration: remove RC12-only audit/provider/capability
keys and factory arguments. Restore the previously validated RC11 Composer lock,
rebuild the container and restart workers. Preserve durable references, namespaces,
bindings, location IDs and all required historical location configurations.
Retain objects and verification keys. RC11 reads RC12-written objects through the
same v1 addresses and ignores identity envelopes. Ordinary overwrites may remove
envelopes, so later RC12 observations can legitimately become `identity_absent`.
Disabling audit or downgrading neither removes objects nor migrates metadata.

## Known limits

No distributed snapshot, anti-replay ledger, historical provenance recovery,
automatic repair or cross-location copy/move API is supplied. Exhausting one page
or location does not establish global completeness. Retired verification keys
make their envelopes `identity_invalid` and invalidate their cursors. Keep the
consumer's observations/comparisons bounded and hold no database transaction
across storage I/O. The existing suite's placeholder skips and mock notices are
listed in the [development report](object-storage-audit-validation.md); critical
audit and real MinIO suites reject skips.
