# Optional object storage audit capabilities (RC12 prerelease)

RC12 remains a prerelease. Audit is disabled by default. The
[RC11 object storage contract](object-storage.md) remains the default. The
existing `TenantObjectStorage` service additionally implements the optional
`TenantObjectStorageAuditInterface`; no methods are added to the mandatory RC11
interface. All types below use `Zhortein\MultiTenantBundle\ObjectStorage`.

## Addresses and correlation claims

| Concept | Contract |
| --- | --- |
| Provider | Logical allocation policy for new objects; never a bucket or endpoint. |
| Location / generation | One immutable opaque technical `locationId`; active or historical within exactly one provider when audit is enabled. No implicit generation numbering. |
| Physical reference | Unchanged `StoredObjectReference` v1 address, including effective target binding and tenant namespace. Its JSON and equality remain identical. |
| Logical identity | Version 1 `LogicalObjectIdentity`: opaque correlation ID, expected reference fingerprint, expected generation and opaque tenant fingerprint. Independent of observed placement. |

Create an identity with `LogicalObjectIdentity::forReference($expectedReference,
$correlationId)`. Generate the correlation ID once with `bin2hex(random_bytes(32))`
and persist it with the expected identity in the consumer's durable store. Never
derive it from filenames, slugs, tenant IDs, emails, personal data or secrets.
The contract validates exactly 64 lowercase hex characters, version 1 and
technical generation IDs. Structure, types, extra fields and unknown versions
are rejected by `fromArray()`. The bundle cannot establish how a caller generated
a correctly shaped identifier: opaque, non-sensitive allocation is a caller duty.

`matchesReference()` compares the entire canonical reference fingerprint, tenant
fingerprint and expected generation. It does not inspect an adapter key.
`sameLogicalObject()` compares tenant and correlation ID; it deliberately does
not require equal placements or equal expected reference fingerprints. Conflicting
claims for the same ID must be retained for consumer review, never merged away.
`ObjectObservation.reference->locationId` is the observed generation;
`ObjectObservation.identity->generation` is the expected generation in the claim.
The consumer owns placement policy and orphan/duplicate classifications.

## Trust boundary

`ObjectStorageAuditCodec` authenticates and encrypts an envelope using AES-256-GCM,
a random 96-bit nonce, a full 128-bit tag, purpose-separated HKDF-SHA256 keys and
versioned associated data including the key ID. It follows the
[PHP authenticated-encryption API](https://www.php.net/manual/en/function.openssl-encrypt.php).
There are separate purposes for identity, location cursors and object cursors.
Ciphertext is bounded and canonical base64url. Metadata envelopes are at most
2048 bytes; cursors at most 12288 bytes (including JSON escaping of anomalous keys).
Invalid tokens never return plaintext.

`verified` means that the envelope authenticates under a retained application key,
has a supported valid structure, and is bound to the current tenant namespace.
The **claim** then supports deterministic comparison with an expected reference.
It does not attest object content, existence at an earlier time, business ownership,
or the correctness of what the writing application claimed. A storage administrator
can copy/replay a valid envelope with arbitrary content or remove it. An actor with
an application key can create claims. Neither encryption nor the presence of
metadata is an absolute provenance guarantee against those actors. No object
content hash, anti-replay ledger or distributed snapshot is promised.

Independent random keys belong to the application, not storage credentials. Each
key is exactly 64 lowercase hex characters. Keep up to 16 named keys; choose one
for new envelopes and retain old keys while old objects or resumable audits need
verification. Rotating storage credentials changes neither physical binding nor
logical identity. Rotating the audit key with previous keys retained also preserves
verification. Removing a key makes its envelopes unverifiable (`identity_invalid`),
and its cursors invalid; it does not change or rewrite any object.

## Configuration and lazy inventory

`object_storage.audit.enabled` defaults to false. OpenSSL is required only when
audit is explicitly enabled; no production Composer dependency changes. Flysystem
and S3 remain optional, and custom backends can implement the generic ports.
Enabled audit requires a declared `ObjectStorageAuditCodec` service. Resolve its
keys at runtime with `%env(...)%` or a trusted factory. Never put literal secret
values, `getenv()` results or resolved secrets into container definitions or dump
them into Symfony's compiled cache. Secret values are validated at runtime;
missing services, unsupported declared capabilities and ambiguous configuration
are rejected at compilation with sanitized errors.

```yaml
services:
    app.storage.audit_codec:
        class: Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec
        arguments:
            $activeKey: current
            $keys:
                current: '%env(OBJECT_AUDIT_KEY)%'
                previous: '%env(OBJECT_AUDIT_PREVIOUS_KEY)%'

zhortein_multi_tenant:
    object_storage:
        enabled: true
        namespace_resolver: app.storage.namespaces
        default_provider: shared
        audit:
            enabled: true
            codec: app.storage.audit_codec
        providers:
            shared:
                active_location: shared_v2
        locations:
            shared_v1:
                provider: shared
                backend: app.storage.old_backend
                binding: app.storage.old_backend
                allowed_tenants: ['*']
                audit_listing: true
                identity_observation: true
            shared_v2:
                provider: shared
                backend: app.storage.backend
                binding: app.storage.backend
                allowed_tenants: ['*']
                audit_listing: true
                identity_observation: true
```

Remove the `previous` entry when starting without an older key. Backend and namespace
services follow the [existing bridge recipe](object-storage-flysystem.md). For the
S3 factory, declare the produced class as `AuditableFlysystemBackend` and pass the
new final argument `audit: true`. Existing factory calls return their original
backend classes. A custom composition must atomically preserve the `Metadata`
write option; arbitrary Flysystem operators are not assumed to support it.

```php
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;

/** @var ContainerBuilder $container */
$container->register('app.storage.audit_codec', ObjectStorageAuditCodec::class)
    ->setArguments(['current', ['current' => '%env(OBJECT_AUDIT_KEY)%']]);
$container->register('app.storage.backend', AuditableFlysystemBackend::class)
    ->setFactory([S3CompatibleStorageFactory::class, 'create'])
    ->setArguments([new Reference('app.storage.config'), '%env(OBJECT_ACCESS_KEY)%',
        '%env(OBJECT_SECRET_KEY)%', false, '%env(OBJECT_CA_BUNDLE)%', true]);
```

The existing registry accepts additional `StorageLocationRegistration` definitions:
descriptor, allowlist and synchronous factory. Symfony uses service closures for
these factories. `inventoryLocations()` on the facade, or `ObjectStorageRegistry::inventory($tenant)`
with an explicit tenant, never invokes a factory, binding service, provider selector,
backend or network client. There is no object allocation. The only retained objects
are server-owned registrations and resolved static backends, never tenant choices.

Each page contains `StorageLocationDescriptor` values: public location ID, provider,
active flag, RC11 `listing`, `auditListing` and `identityObservation` capability flags.
The order is ascending bytewise location ID, independent of configuration insertion
order. Page size is 1–1000. Hidden dedicated locations are omitted. All registered,
allowed historical generations are included even without any remaining consumer
reference. No discovery of unregistered buckets, providers or locations occurs.

When audit is enabled, every historical location must declare its provider. The
owner of an active location may be inferred only when unambiguous. Duplicate IDs,
allowlist entries, conflicting owners and active flags are rejected. Advertised
capabilities must match the declared backend class and are checked again when a
registration resolves. A backend without identity support reports `unavailable`;
unsupported tolerant listing throws before I/O. Empty explicit registries are valid.

`auditScope($locationId)` selects only that allowed location, constructs its client
if needed, validates its effective binding and returns a `StorageAuditScope` without
an object allocation. No other provider is opened. Inventory itself does not call
this method. The registry revision fingerprints ordered descriptors, allowlists and
provider mappings; it does not instantiate every backend to inspect effective targets.
Object scopes/cursors separately bind the selected effective physical fingerprint.
Changing an addressed target still requires a new immutable location ID.

## Writing and observing

`writeWithIdentity($reference, $content, $identity)` and
`writeFromStreamWithIdentity($reference, $stream, $identity)` explicitly opt into
an atomic content-plus-envelope write. Streams use the existing caller ownership,
current-position, 64 KiB chunk and reset contract. The qualified physical address
does not change. A same-tenant identity may be carried to another explicitly chosen
placement; its original expected-reference claim remains unchanged. Cross-tenant
claims are rejected before I/O.

RC11 `copy()` and `move()` still allow only distinct keys in one location. Auditable
backends must preserve the envelope; S3 uses the existing
[COPY metadata directive](https://docs.aws.amazon.com/AmazonS3/latest/API/API_CopyObject.html).
Cross-location operations remain unsupported. Tests also simulate an operator's
cross-generation copy to prove that observation retains the original claim.
Ordinary RC11 writes remain valid and write no identity. An overwrite by an ordinary
S3 write removes the previous envelope. There is no automatic metadata backfill.

`observe($reference)` returns one `ObjectObservation`. `auditList($scope, $limit,
$cursor)` returns a bounded `ObjectAuditPage` of the same values. The S3 bridge
performs one bounded LIST and at most one HEAD per valid entry; it downloads no
content. Size/date and identity come from the same HEAD. The generic technical
ports are `AuditListingBackendInterface`, `ObjectIdentityObserverInterface`,
`ObjectIdentityBackendInterface` and `BackendIdentityObservation`; consumer code
never needs Flysystem or SDK types.

| Observation state | Meaning and exposed data |
| --- | --- |
| `verified` | Same-tenant authenticated claim, observed reference, size/date and identity. |
| `identity_absent` | Existing object with no envelope; observed address and metadata, no logical identity. |
| `identity_invalid` | Malformed/oversized envelope, unsupported version, unknown key or authentication failure; address and metadata, no raw envelope. |
| `foreign` | A valid authenticated envelope claims another tenant; random observation ID only, no reference, namespace, metadata or identity. |
| `invalid_reference` | In-prefix physical key cannot form a v1 reference; random observation ID only, no HEAD of that key. |
| `unreachable` | Metadata I/O failed, including ambiguous S3 404, network or TLS failure; valid observed address, no identity. |
| `indeterminate` | Explicit object-not-found after listing/direct observation; no logical identity. |
| `unavailable` | Backend has no identity capability; no invented identity and no metadata I/O. |

Individual anomalies do not block a subsequent page when the physical namespace
boundary is still guaranteed. A **foreign physical key**, missing/non-string key,
invalid global response, unordered/duplicate/oversized page, empty truncated page,
invalid cursor, changed binding or tenant/reset remains a page error. The whole
page is validated before any per-entry HEAD. No partial page escapes such errors.
Exception messages contain only structured public reasons; exposed sanitized errors
have `getPrevious() === null`. Consumers must treat a page failure as incomplete,
never as absence or proof of an orphan.

Object cursors are encrypted, authenticated and bound to namespace, location,
binding, registry revision and page limit. Location cursors bind the explicit tenant,
registry revision and limit; facade location cursors also bind namespace. Do not
decode, synthesize or reuse them for another query. Opaque entry identifiers are
diagnostic handles, not operations or authorization tokens. No raw malformed key,
bucket, endpoint or credential is returned. Cursor reuse after a reset is possible
only after reestablishing the same valid scope; in-flight I/O is invalidated.

## Generic inverse audit example

```php
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageAuditInterface;

/** @var TenantObjectStorageAuditInterface $audit */
// A trusted execution boundary has already selected and authorized the tenant.
$locationCursor = null;
do {
    $locations = $audit->inventoryLocations(50, $locationCursor);
    foreach ($locations->locations as $location) {
        if (!$location->auditListing) {
            // Persist an incomplete-location finding in the consumer's audit run.
            continue;
        }
        $scope = $audit->auditScope($location->locationId);
        $objectCursor = null;
        do {
            $page = $audit->auditList($scope, 100, $objectCursor);
            // Collect valid reference->toJson() values from this page only.
            // Batch-fetch expected application references and persisted identities.
            // Compare identity->matchesReference($expected) and sameLogicalObject().
            // Retain conflicting claims and all uncertain/anomalous observations.
            // Persist this bounded page before requesting another one; do not hold
            // a database transaction or lock across object I/O.
            $objectCursor = $page->nextCursor;
        } while (null !== $objectCursor);
    }
    $locationCursor = $locations->nextCursor;
} while (null !== $locationCursor);
```

To find repeat claims across pages/generations without a global in-memory catalogue,
the consumer can index its audit-run observations by tenant plus correlation ID and
perform bounded queries against that durable index. The bundle supplies comparisons
and paginated inputs; it does not query an application database, retain the entire
catalogue, assign business classifications or guarantee database query performance.
No snapshot is promised under concurrent writes. Exhausting one page or location
does not prove completion of all locations. No repair, deletion, quarantine,
reallocation, automatic retry or compensating write is part of these audit methods.

## Historical RC11 objects and migration

Historical references and objects remain readable and exactly resolvable. Their
addresses can still be matched using unchanged v1 JSON and `equals()`. A historical
object without an envelope has **no logical provenance supplied by this API**:
`identity_absent` is indeterminate for logical correlation, not an orphan verdict.
Equal content, dates, sizes or opaque key fragments cannot invent that provenance.
See the [RC11 to RC12 migration procedure](migration-rc11-to-rc12.md).
