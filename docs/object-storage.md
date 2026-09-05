# Tenant-aware object storage core

The opt-in `ObjectStorage\TenantObjectStorageInterface` is a backend-independent,
non-AWS contract. All names below belong to `Zhortein\MultiTenantBundle\ObjectStorage`
unless qualified otherwise. This core ships **no operational backend**, Flysystem
bridge, S3 SDK or MinIO service, and requires no new production dependency.
Application services supply `ObjectStorageBackendInterface` and
`StorageLocationBindingInterface`. The permanent tests use instrumented synthetic
targets. Real adapter and physical-target derivation proofs belong to the next lot.

## Coexistence with RC10 file storage

`Storage\TenantFileStorageInterface`, `LocalStorage`, `S3Storage`, their public
constructors, service IDs and aliases retain their existing behavior. The old
`storage` block and its defaults are unchanged. Historical paths remain
`tenants/{slug}/{relative-path}`. RC10 `S3Storage` is historical and incomplete:
its S3 operations remain stubs; enabling the new API never makes them operational.
No paths, namespaces, references or objects are converted or moved automatically.
See [legacy file storage](storage.md). Deprecation of that incomplete adapter can
be considered separately once an operational replacement exists.

`object_storage` defaults to disabled and registers no operational services when
disabled. Existing applications compile without Flysystem, including the minimal
production installation and external Consumer App matrix.

## Provider, location, binding, namespace and reference

A **provider** is a server-configured logical allocation policy (`shared`). A
**location** is an immutable generation (`shared_v1`) identifying a physical
target. Keep old generations registered while their references exist. A provider
points at one active generation for *new* allocations only.

A **binding** is a SHA-256 fingerprint of a canonical `PhysicalStorageIdentity`.
`StorageLocationBindingInterface::identity($backend)` must derive it from the
selected backend's **effective** addressing configuration, synchronously and
without storage I/O. Do not return a separately maintained hard-coded digest.
The bundle hashes deterministic JSON with these fields, in this order:

- `schema`: identity schema version, currently `1`;
- `backend`: adapter type and addressing semantics;
- `endpoint`: canonical endpoint or equivalent stable target identity;
- `root`: bucket/container and effective root, including any adapter prefix;
- `options`: addressing-affecting options, sorted by string key, with canonical
  string values (for example path-style versus virtual-host addressing).

Adapters must canonicalize equivalent endpoints, default ports, root separators
and relevant options consistently. Any change that changes the addressed target
must change the identity. Include all wrapper roots/prefixes. Renewable
credentials, access tokens, connection timeouts and retry policy **must not**
enter this identity. Neither identity fields nor option values may contain
secrets. The core does not interpret URLs, receive endpoint configuration or
infer an adapter's effective target: the binding service is a trusted server
extension point. The synthetic contract proves target/root/options changes and
credential rotation; the next bridge must prove actual configuration derivation.
A changed bucket, endpoint or root requires a **new location ID**. Reusing an ID
with a changed binding rejects old references before backend I/O.

A **tenant namespace** is a unique, stable random 256-bit value rendered as 64
lowercase hexadecimal characters. Provision it once on the server, independently
of slug and business fields. Never accept it from HTTP input. The optional
`ConfiguredTenantStorageNamespaceResolver` takes a server-owned map of immutable
tenant IDs to namespaces and rejects duplicates and unknown tenants. A custom
`TenantStorageNamespaceResolverInterface` must enforce the same uniqueness and
stability durably; the core cannot verify global uniqueness in an external
resolver. Do not recycle tenant IDs or namespaces.

A **reference** is `final readonly StoredObjectReference`. Its only fields are
`locationId`, `locationBinding`, `tenantNamespace`, `key`, `formatVersion`.
Allocation generates a random 256-bit key with `random_bytes(32)` and performs
no backend I/O. Names, slugs, emails, original filenames, document titles, paths,
endpoints, ACLs and credentials have no place in the reference. A reference is
an address, never authorization. Location IDs use `[A-Za-z][A-Za-z0-9_-]{0,63}`;
choose technical IDs without business or sensitive content. Binding, namespace
and key use exactly 64 lowercase hexadecimal characters.

Persist `toArray()` in a Doctrine JSON column or `toJson()` in a string column.
The stable JSON field order is `formatVersion`, `locationId`, `locationBinding`,
`tenantNamespace`, `key`, without whitespace. Restore with `fromArray()` or
`fromJson()`; extra/missing fields, incorrect types and unknown versions fail.
`equals()`, `jsonSerialize()`, string conversion and PHP serialization use this
same representation. Symfony Serializer constructor-based denormalization also
uses the validated public constructor. For Messenger, an application message
implementing `TenantAwareMessageInterface` carries this reference and its normal
`TenantStamp`; PHP serialization validates on restoration. Never send a backend,
physical path, credentials, PHP resource or closure in a message.

Persisting the location and binding is necessary because changing `shared` from
`shared_v1` to `shared_v2` does not move historical objects. Reads of old references
still resolve **exactly `shared_v1`**. Unknown, unavailable or removed locations
never trigger a provider search, fallback, migration or reallocation.

## Configuration

```yaml
zhortein_multi_tenant:
    object_storage:
        enabled: false
        default_provider: shared
        namespace_resolver: null
        provider_selector: null
        tenant_overrides: {}
        providers: {}
        locations: {}
        temporary_urls:
            enabled: false
            default_ttl: 300
            max_ttl: 900
```

An enabled application registers its own backend and binding services:

```yaml
zhortein_multi_tenant:
    object_storage:
        enabled: true
        default_provider: shared
        namespace_resolver: app.storage.namespaces
        tenant_overrides:
            'tenant-immutable-id': dedicated
        providers:
            shared:
                active_location: shared_v2
            dedicated:
                active_location: dedicated_v1
        locations:
            shared_v1:
                backend: app.storage.old_backend
                binding: app.storage.old_binding
                allowed_tenants: ['*']
            shared_v2:
                backend: app.storage.backend
                binding: app.storage.binding
                allowed_tenants: ['*']
                temporary_urls: true
            dedicated_v1:
                backend: app.storage.dedicated_backend
                binding: app.storage.dedicated_binding
                allowed_tenants: ['tenant-immutable-id']
        temporary_urls:
            enabled: true
            default_ttl: 300
            max_ttl: 900
```

Use the same backend and binding service ID when one service implements both
contracts. Factory services must declare their resulting class so compilation
can validate the implemented interfaces. Missing services, wrong types, invalid
IDs, duplicate registry definitions across configuration fragments, unknown
providers/locations, empty allowlists and inconsistent TTLs fail explicitly.
Tenant IDs in allowlists are strings. The standalone `'*'` means every valid
active tenant **inside its own namespace**, never global or cross-tenant access.
An empty string has no wildcard/default meaning.

`provider_selector` optionally identifies a custom
`TenantStorageProviderSelectorInterface`; when null, the bundle uses
`ConfiguredTenantStorageProviderSelector` with `default_provider` and
`tenant_overrides`. A custom selector must return a registered logical ID.
Unknown dynamic selections fail at allocation. Namespace resolution is required
when enabled; there is deliberately no slug-derived default.

The facade alias is `TenantObjectStorageInterface` →
`zhortein_multi_tenant.object_storage`. Public contracts also include
`ObjectStorageRegistry`, `StorageLocation`, `PhysicalStorageIdentity`,
`ObjectMetadata`, `ObjectListingPage`, `BackendObjectPage`, `TemporaryObjectUrl`,
the selector/resolver and backend/binding/stream interfaces.

## Operations and isolation

Before every operation the facade requires a valid active tenant, revalidates
the reference/version, resolves and compares its namespace, resolves the exact
location, verifies its effective binding, and checks its allowed tenant IDs.
Only then does it qualify the key as `objects/v1/{namespace}/{key}` and call the
backend. The separator boundary is mandatory. Traversal, percent encodings,
backslashes, extra separators and already-prefixed keys fail validation.
This applies equally to `exists`, idempotent `delete`, listing scope/cursor,
source **and destination**, and URL signing. Infrastructure permission to use a
location does not replace application authorization for an object.

`write` overwrites one object; `read` materializes its content. Use
`writeFromStream` and `readToStream` for large contents. The caller supplies and
owns a PHP stream at its current position. The facade never rewinds or closes
it, and accepts non-seekable streams. Each synchronous backend receives only a
scoped `ObjectStreamSourceInterface` or `ObjectStreamDestinationInterface`, with
chunks bounded to 64 KiB. The interfaces intentionally expose no PHP resource,
seek operation or backend handle. They are invalidated in `finally`, on success
and failure. Retaining one cannot extend its usable lifetime. Every chunk checks
the active scope; context changes and resets interrupt subsequent transfers.
An adapter needing a native resource must bridge these chunk interfaces inside
the operation and close every resource it acquires before returning/throwing.
It must bound memory and must not retain callbacks or yield asynchronous work.
Interruption can leave a partial caller stream or remote write; callers own
retry/cleanup decisions. This first contract is synchronous; sharing a mutable
tenant context between concurrently executing fibers is unsupported.

`list($scope, $limit = 100, $cursor = null)` uses any valid reference as its scope;
the scope object's key need not exist, so `allocate()` can supply it. Limits are
1–1000. Results are materialized, lexicographically ordered references with an
optional opaque `nextCursor`. Pass the cursor unchanged with the same scope
location and tenant. The cursor carries a validated reference as a canonical
base64url keyset position, never an adapter cursor or physical target. It is not
authorization or a secret. The backend receives only an exclusive qualified
`afterKey` under the exact tenant prefix. Oversized, malformed, foreign,
duplicate or unordered backend results fail without returning a partial page.
There is no unbounded listing, recursive deletion, lazy tenant iterator or
snapshot-consistency guarantee during concurrent mutations.

`copy` and `move` validate both references before I/O and accept different keys
in the **same location** only. Inter-location and self transfers are explicitly
unsupported. Existing destination content may be overwritten. The backend must
throw when the source is absent. Normal return means the operation completed;
there is **no universal atomicity guarantee**. The facade never implements move
as an implicit copy/delete sequence and never deletes after an ambiguous copy.
Adapters implementing that sequence must follow the same rule.

## Errors and outcomes

`Exception\ObjectStorageException` implements the bundle's public
`Exception\MultiTenantException` marker without modifying the final historical
`TenantStorageException`. Its `reason` is an `ObjectStorageError` enum:
`MISSING_CONTEXT`, `INVALID_REFERENCE` (including unsupported format version),
`FOREIGN_REFERENCE`, `UNKNOWN_PROVIDER`, `UNKNOWN_LOCATION`, `BINDING_MISMATCH`,
`TENANT_NOT_ALLOWED`, `UNSUPPORTED_OPERATION`, `OBJECT_NOT_FOUND`,
`INVALID_ARGUMENT`, `CONTEXT_CHANGED`, or `BACKEND_FAILURE`.

Adapters must use `ObjectStorageBackendException` and its `OperationOutcome`:

- `NOT_APPLIED`: the backend knows the operation did not apply;
- `UNKNOWN`: effects cannot be established, including unexpected adapter errors;
- `PARTIAL`: some effects are known to have applied.

These are technical observations, not a distributed transaction protocol.
Unavailability must never become `exists() === false`. Unexpected adapter
messages and exception chains are sanitized rather than exposing credentials or
physical paths. A binding failure before I/O is `NOT_APPLIED`; after backend entry
an interruption is conservatively `UNKNOWN`. The consumer must decide its
idempotency strategy, retry policy and handling of partial/unknown results.
No automatic retries, fallback or compensating source deletion occur.

## Private objects and temporary URLs

Objects are private by default. There is no permanent public URL API. Temporary
URLs require both global enablement, per-location enablement and a backend
implementing `TemporaryObjectUrlBackendInterface`. The caller's positive TTL
must not exceed configured `max_ttl`; default must be positive and no greater
than maximum. The core additionally caps the configured maximum at 86400 seconds.
Unsupported capability throws explicitly, after reference validation and before
signing. `TemporaryObjectUrl` contains an HTTPS URL and its `DateTimeImmutable`
expiration; expired or overlong backend expirations are rejected. HTTPS-only
URLs intentionally avoid exposing bearer access over plaintext transport.

The application **must authorize access before calling** `temporaryUrl()`.
An issued bearer URL remains usable until expiration, even if the tenant
context is reset or application permissions change. Expiration is not revocation.
The next adapter must prove that its actual signature expiration matches this
contract; a synthetic URL alone is not an S3 compatibility claim.

## Lifecycle and responsibilities

The facade retains only an invalidation token. It caches no last tenant,
selection, reference, page or backend handle. The registry is immutable and
indexed by location; bindings are recomputed rather than cached. The facade is
registered for Symfony `kernel.reset`,
`zhortein_multi_tenant.lifecycle_resetter`, and context start/end events.
Context reset invalidates logical tenant state before fallible resource cleanup.
Facade reset is idempotent and performs no backend I/O. Ordinary A/B/A operations
revalidate durable references on every call; a reference or page kept by an
application grants no access in B or NONE. Issued URLs have their separate
expiration lifecycle. Messenger success, exception and global-message paths
use the existing bundle lifecycle without a new worker or middleware policy.

The bundle owns technical addressing, isolation, bounded operations, structured
errors and reset. The application owns persisted object records, authorization,
original names, business MIME/state, antivirus/quarantine, quotas, billing,
retention, trash, business audits, HTTP uploads and user interfaces. No document
entity, product-specific policy or automatic business migration is introduced.

## Adapter test contract

Extend optional `Test\ObjectStorageBackendTestCase` and implement
`createBackend()` using a fresh disposable target. Its shared contract exercises
content/streams, absence, metadata, ordered bounded prefix listing, copy/move and
idempotent deletion. Adapter suites must additionally exercise real target
binding, private defaults, interruption and `NOT_APPLIED`/`UNKNOWN`/`PARTIAL`
outcomes, including that an unknown copy never triggers source deletion.
`tests/Unit/ObjectStorage` instruments every synthetic I/O and proves negative
isolation across all facade primitives; `tests/Integration/ObjectStorage` proves
container and persistent Worker lifecycle behavior. No real object-store service
is started by this core's tests.
