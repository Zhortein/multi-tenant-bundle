# Optional Flysystem and S3-compatible storage

This bridge implements the [object storage core](object-storage.md). The legacy
RC10 file API, references, namespace rules and confidentiality contract remain
unchanged. It was introduced in RC11. RC12 also remains a prerelease and adds the optional
[audit composition](object-storage-audit.md#configuration-and-lazy-inventory).
Existing factory calls remain unchanged. Audit requires explicit `audit: true`,
an `AuditableFlysystemBackend` declaration and the separate audit block. Ordinary
overwrites can remove identity metadata; no automatic backfill runs.

## Installation and dependency graphs

Flysystem, its S3 adapter and the SDK appear in `suggest` and this repository's
`require-dev` only. None is a production requirement of the bundle. Installing
the bundle as a dependency never installs its development dependencies. Keep
`object_storage.enabled: false` for an unchanged minimal installation.

Consumers enabling S3-compatible storage install:

```sh
composer require 'league/flysystem:^3.30.2' \
  'league/flysystem-aws-s3-v3:^3.30.1' 'aws/aws-sdk-php:^3.371.5'
```

Non-S3 operators need only Flysystem and their own bounded listing capability.
There is no automatic bridge service registration. Enabling an explicit bridge
definition without its dependencies fails compilation with a Composer instruction
before the bridge class is loaded. No vendor stubs, aliases or debug fallback
are provided.

| Exact tested graph | Flysystem | S3 adapter | SDK |
|---|---|---|---|
| Lower | 3.30.2 | 3.30.1 | 3.371.5 |
| Upper | 3.36.0 | 3.35.3 | 3.394.9 |

The SDK lower bound excludes versions blocked by Composer advisories, including
the [upstream SDK advisory](https://github.com/aws/aws-sdk-php/security/advisories/GHSA-27qh-8cxx-2cr5).
No advisory is ignored. The first local resolution added 14 development packages
and updated no existing locked package: Flysystem, local/S3 adapters, MIME
detection, SDK/CRT, JMESPath, Guzzle/Promises/PSR-7, PSR HTTP interfaces and a
polyfill. The main lock remains ignored; each MinIO CI cell retains its actual
resolved lock as an artifact. The external consumer pins the three optional
direct versions and resolves its own lock independently.

## Common construction and explicit services

All bridge classes below belong to
`Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem`.
`S3LocationConfiguration` is immutable and contains no credentials. The same
object constructs the client, adapter, listing, signer and physical identity.
An explicit HTTPS S3-compatible endpoint is mandatory. The region is a protocol
signing scope only, with no tenant or business meaning. Its default `us-east-1`
is accepted by MinIO. No Amazon hostname, CloudFront, ARN, STS, KMS or public ACL
is required.

```yaml
services:
    app.object_config:
        class: Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration
        arguments:
            $endpoint: '%env(OBJECT_ENDPOINT)%'
            $bucket: '%env(OBJECT_BUCKET)%'
            $root: object-data
            $pathStyle: true
            $region: '%env(OBJECT_SIGNING_REGION)%'
            $signingEndpoint: '%env(OBJECT_SIGNING_ENDPOINT)%'
            $maxTtl: 900
    app.object_backend:
        class: Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\SigningFlysystemBackend
        factory: ['Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory', create]
        arguments:
            $config: '@app.object_config'
            $accessKey: '%env(OBJECT_ACCESS_KEY)%'
            $secretKey: '%env(OBJECT_SECRET_KEY)%'
            $temporaryUrls: true
            $caBundle: null
    app.object_namespaces:
        class: Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver
        arguments:
            $namespaces:
                tenant-immutable-id: '%env(OBJECT_TENANT_NAMESPACE)%'

zhortein_multi_tenant:
    object_storage:
        enabled: true
        namespace_resolver: app.object_namespaces
        default_provider: shared
        providers:
            shared:
                active_location: shared_v1
        locations:
            shared_v1:
                backend: app.object_backend
                binding: app.object_backend
                allowed_tenants: ['*']
                temporary_urls: true
        temporary_urls:
            enabled: true
            default_ttl: 300
            max_ttl: 900
```

`caBundle: null` uses the system trust store; a custom CA file may be supplied.
For an unsigned backend, declare `FlysystemBackend` as the factory result class
and pass `temporaryUrls: false`. Register these services only when enabled.
Applications provision the stable namespace map; no input-derived default exists.
Dedicated locations and `tenant_overrides` follow the core configuration and only
select new allocations.

## Physical identity and trust boundary

The binding uses the core's schema-1 deterministic JSON and SHA-256. Backend type
is `flysystem-s3-v3`, endpoint is the canonical internal origin, container is the
bucket, and sorted options are `format=1`, `root`, `path_style`, `signing_endpoint`
and `signing_region`. Credentials, CA paths, timeouts and retry policy are absent.

Canonicalization lowercases the HTTPS scheme and DNS host, removes port 443 and
the endpoint root slash, and strips leading/trailing technical root slashes.
Endpoint paths, query strings, fragments, user information, control characters,
non-HTTPS schemes and encoded hosts are rejected. This implementation accepts
DNS/IPv4 hosts, not IPv6 literals. Technical root segments accept ASCII letters,
digits, underscores and hyphens; empty internal, dot, traversal, encoded and
backslash segments are rejected. Bucket and signing-scope validation is deliberately
conservative. These canonical values construct the adapter itself.

Credential rotation preserves the binding; the test reads and writes using a
second actual MinIO account through the original reference. Endpoint, bucket,
root, addressing-mode or signing-endpoint changes alter the binding. Reusing
`shared_v1` then rejects old references before backend entry. Create `shared_v2`
for a different target and retain `shared_v1` while references exist. Changing
the provider does not reroute historical objects or migrate data.

An arbitrary `FilesystemOperator` cannot reveal its effective target through
Flysystem's public API. `FlysystemBackend` therefore requires an explicit
`PhysicalStorageIdentity` and `KeysetListingInterface` from trusted consumer
construction. They must describe the same immutable target, including every
wrapper root. This is a declared trust boundary, not inspection. No private API
or reflection is used. Do not mutate an operator's addressing after construction.

## I/O, bounded listing and errors

The bridge receives qualified `objects/v1/{namespace}/{key}` keys. It never
selects a tenant/provider/bucket from a key and independently rejects malformed
qualified keys. S3 capabilities add only the fixed technical root; results strip
that root while preserving the complete tenant prefix.

Writes explicitly request private visibility. Downloads copy chunks of at most
64 KiB and close owned streams in `finally`. Uploads consume the caller's current
position through the core chunk interface, spool into
`php://temp/maxmemory:65536`, then pass the owned resource to Flysystem. This
supports non-seekable input and prevents Flysystem's rewind from changing the
caller's position. Large uploads require temporary disk capacity. Caller streams
stay open, including after failure. String reads intentionally materialize content.

`FilesystemOperator::listContents()` has neither a portable keyset cursor nor
an ordering guarantee. The required explicit capability avoids a hidden full
scan. S3 uses exactly one `ListObjectsV2` request with a qualified prefix,
`MaxKeys <= 1000` and exclusive `StartAfter`; `IsTruncated` feeds the core cursor.
There is no snapshot guarantee during concurrent writes.

S3 existence uses successful `ListObjectsV2`, bounded to one exact-key prefix.
It requires permission to list that prefix and distinguishes absent objects from
inaccessible/missing buckets. Malformed or out-of-scope responses fail closed.
The SDK's general `doesObjectExistV2()` turns all
HTTP 404 responses into false and cannot provide this distinction. Generic
operators must uphold that distinction or supply `ExistenceCheckerInterface`.

Copy/move use Flysystem's public methods, only within one tenant prefix and one
core location. S3 move copies then deletes and is not atomic. Failed copy never
triggers compensating deletion by the bridge. Failed move is `UNKNOWN` because
the operator does not expose which step completed. Deletion never recurses.

The #64 exception contract is preserved: `getPrevious()` remains null, messages
contain only documented reasons, and no vendor diagnostic is copied into a
public field or log. No logging or debug mode is added. The stable backend code
is `BACKEND_FAILURE`; retryability is not guessed. Failed pre-upload spooling is
`NOT_APPLIED`; interrupted downloads after a completed destination chunk are
`PARTIAL`; ambiguous backend calls are `UNKNOWN`. Consumers own deep
infrastructure observability, idempotency and recovery.

## Temporary URLs

`SigningFlysystemBackend` uses the explicit core signing capability. It never
assumes a signing method on `FilesystemOperator`. S3 signs `GetObject` for the
exact bucket/root/key and future expiration. The facade enforces positive TTLs
and its maximum; S3 configuration also enforces a maximum, at most 86400 seconds.
Keep both maxima aligned. There is no permanent public URL API.

Internal and public endpoints may differ, but both must reach the same bucket
and root. Their relationship is declared at construction. A separate client is
constructed for the public endpoint before signing; no hostname is replaced
afterwards. HTTPS and certificate verification are required for both endpoints.
The MinIO proof uses two Docker DNS aliases of one TLS server, verifies the
actual signed download, and checks expiry rejection by MinIO.

The application must authorize access before signing. An issued bearer URL
remains usable until expiry independently of context resets and business
permission changes. Expected signature parameters include a signing credential
identifier. Never log a signed URL.

## Disposable MinIO test kit

After restoring development dependencies, run:

```sh
sh tests/ObjectStorage/run-minio.sh
```

The kit belongs exclusively to this repository. Each run creates a unique
`mtb-object-test-*` Compose project with no published host port. Data uses tmpfs;
no persistent data volume is required. Fresh one-day test certificates are
generated without printing the private key; readiness uses verified HTTPS.
Teardown removes only that invocation's containers, network and certificate
directory. Tests create private `mtb-object-*` buckets and clean their own
objects/buckets. Synthetic accounts are restricted to this disposable server;
the second account proves credential rotation.

| Official image | Exact tag | Verified multi-platform digest |
|---|---|---|
| `quay.io/minio/minio` | `RELEASE.2025-09-07T16-13-09Z` | `sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e` |
| `quay.io/minio/mc` | `RELEASE.2025-08-13T08-35-41Z` | `sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727` |

Provenance: [MinIO release](https://github.com/minio/minio/releases/tag/RELEASE.2025-09-07T16-13-09Z),
[mc release](https://github.com/minio/mc/releases/tag/RELEASE.2025-08-13T08-35-41Z),
and registry manifests checked with `docker buildx imagetools inspect`.

The dedicated PHPUnit configuration fails on missing MinIO or skipped tests.
Ordinary PHPUnit does not claim to execute this network suite. Real tests cover
shared namespaces, dedicated override, historical generations, neighbor buckets,
streams/listing, private defaults, signing/expiry, invalid credentials/endpoints,
and a single production kernel and Worker across serialized success/failure
messages. Instrumentation counts backend entry for foreign references and changed
bindings. Targeted doubles additionally cover deterministic ambiguous failures.

The [external consumer](../tests/ObjectStorage/Consumer/README.md) compiles
production containers with and without Flysystem using only public bundle APIs.
Its kernel/message/handler also drive the real MinIO Worker proof. Both exact
bridge graphs run across PHP 8.3/8.4/8.5 and compatible Symfony 7.4/8.0/8.1
combinations. Existing PostgreSQL 16/18, Scheduler, Messenger, lifecycle, cache
and exact-consumer jobs remain. The bridge introduces no SQL.

## Upgrading from RC10

Opt in explicitly, install optional packages when needed, provision durable
opaque namespaces, construct immutable locations and persist references. Retain
the old file API and historical object locations independently. Applications own
data conversion and inter-provider migration, authorization, business records,
quotas, antivirus and retention. MinIO is an S3-compatible validation target,
not a required production provider. Upgrading executes no production migration.
Follow the [RC10 to RC11 migration guide](migration-rc10-to-rc11.md) for initial
bridge adoption and the [RC11 to RC12 guide](migration-rc11-to-rc12.md) for audit.
