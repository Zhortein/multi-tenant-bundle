# Migrating from RC10 to RC11

RC11 is a prerelease, not a stable 1.0 contract. Upgrading the package executes
no production database or object migration. Object storage remains disabled
unless the consumer explicitly configures it. No document entity, quota,
application infrastructure or business rule is added.

## Upgrade without activating object storage

After RC11 is published, resolve the package in the application's own documented
environment and review the resulting dependency lock:

```sh
composer require 'zhortein/multi-tenant-bundle:1.0.0-rc.11'
```

Keep the existing `storage` configuration. Leaving `object_storage` absent is
equivalent to:

```yaml
zhortein_multi_tenant:
    object_storage:
        enabled: false
```

Flysystem, its S3 adapter and the SDK are not installed transitively. Messenger
remains a required runtime component, and its integration can still be disabled
independently. PHP and Symfony/Doctrine production constraints are unchanged.
Compile the application's production container and run its normal regression
suite before rolling out. See [compatibility](compatibility.md).

## Global Messenger and Scheduler dispatch

RC10 could add a contradictory `TenantStamp` in transport resolution when an
application dispatched a global message while tenant A was active. Its receiving
Worker then rejected that message. RC11 applies the existing recursive message
classification before adding any tenant stamp or tenant-specific route.

Global messages and recognized `RedispatchMessage` wrappers now retain normal
Symfony routing without an automatic tenant stamp. Sending leaves the caller's
tenant lifecycle intact. The receiving Worker clears stale tenant state before
global handling and resets after success or failure. An explicitly stamped
global message remains invalid and is rejected; RC11 never silently strips it.

Keep exactly one public classification marker per application message. Existing
contradictory messages persisted by RC10 do not become valid through an upgrade.
Inspect affected application queues and make an authorized consumer recovery
decision; the bundle does not rewrite or replay queued messages automatically.
Review [Messenger](messenger.md), [Scheduler](scheduler.md) and the
[historical reproducer](../reproducers/messenger-rc10-global/README.md).

## Coexistence with the historical file API

`Storage\TenantFileStorageInterface`, `LocalStorage`, `S3Storage`, constructors,
service IDs, aliases and the old `storage` block keep their RC10 behavior.
Historical local paths remain `tenants/{slug}/{relative-path}`. The historical
`S3Storage` implementation remains incomplete with stubbed S3 operations; RC11
does not make that API operational. See [legacy storage](storage.md).

The new `ObjectStorage\TenantObjectStorageInterface` is a separate opt-in API.
Neither API converts historical paths, generates replacement references, scans
old files or moves objects automatically. A consumer wishing to migrate old
files must design, authorize and validate that migration separately.

## Explicitly enable the new API

1. Choose a backend and provision its physical target independently. MinIO is a
   real S3-compatible proof target, not a required production provider. No Amazon
   hosting service is required.
2. For Flysystem, explicitly install `league/flysystem:^3.30.2` and the chosen
   adapter in the consumer. For the supplied S3-compatible factory, also install
   `league/flysystem-aws-s3-v3:^3.30.1` and `aws/aws-sdk-php:^3.371.5`. Non-S3
   adapters need no S3 SDK. The generic core itself needs none of these packages.
3. Provision one stable random 256-bit namespace per immutable tenant ID,
   independent of slug, email, original filename or other business data. Enforce
   uniqueness and durability; never regenerate namespaces on process boot.
4. Register Symfony backend, physical-binding and namespace-resolver services.
   Configure logical providers and immutable physical location generations in
   `object_storage`, with explicit tenant allowlists. Set `enabled: true` only
   with these services present. Use the complete [core configuration](object-storage.md#configuration)
   and [Flysystem service example](object-storage-flysystem.md#common-construction-and-explicit-services).
5. Allocate a technical `StoredObjectReference` for each new object and persist
   its complete `toArray()` or `toJson()` representation in the consumer. Restore
   it with `fromArray()` or `fromJson()`. Persisting just the key, current
   provider or filename is insufficient. The reference is an address, never
   application authorization.
6. Authorize each operation in the application, then call the facade under the
   intended tenant context. Messenger messages may carry the serialized
   reference with their normal tenant classification; do not serialize a
   backend, resource, endpoint or credentials.

The default provider and tenant overrides choose a location only for allocation.
Existing objects always use their persisted location and binding. Missing or
unavailable locations never trigger a fallback, backend search or reallocation.

## Physical changes and retained generations

An endpoint, bucket, root or other addressing change requires a new location ID,
for example `shared_v2`. Construct that generation from its actual immutable
target, then point the provider's `active_location` at it for new objects. Keep
`shared_v1` configured and accessible while any persisted reference uses it.
Rebinding an existing generation to a different target deliberately rejects old
references before storage I/O. Copy and move between locations are refused.

Credentials are excluded from the deterministic physical binding. Rotating
credentials for the same physical target preserves references. With the supplied
factory, signing origin and scope also enter the binding; create a new generation
if they change. Public/internal signing origins must reach the same target.
Never rewrite a hostname after signing. Existing signed URLs remain bearer
access until expiry; context reset does not revoke them.

## Consumer responsibilities and rollback

The consumer owns authorization, durable object records, idempotence, retry and
reconciliation of unknown/partial outcomes, quotas, retention, antivirus,
document rules, original names and business metadata. S3 move is not atomic.
The bundle adds no business workflow, automatic retry or compensating deletion.
Use the [persistent lifecycle contract](persistent-lifecycle.md) for kernels and
workers; reset does not delete objects or persisted references.

Before rollback, stop new use of the added API and account for queued messages
and records containing its references. RC10 cannot load RC11 object storage
classes. Keep the reference records and historical locations; disabling an
integration is not a data rollback. Any conversion, queue recovery or subsequent
consumer deployment is a separate application task.
