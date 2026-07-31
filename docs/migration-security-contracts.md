# Migration: Secure Integration Contracts

These changes are intentional security-related backward incompatibilities for the next 1.0 release candidate. The repositories have no current production consumer, so no silent legacy mode is provided.

## Storage

Previously, missing tenant context could use an implicit `default/` prefix and tenant files could live directly below the storage root. This could collide with a tenant named `default` and made tenant-less access ambiguous.

The new contract:

- throws `TenantStorageException` when an enabled tenant-aware operation has no tenant;
- stores files under `tenants/{safe-tenant-slug}/...`;
- rejects unsafe and encoded paths before normalization or backend access;
- rejects local symbolic-link escapes;
- requires a distinct explicit service for global files.

Move existing files before deploying this contract. For each known tenant slug `S`, move files from the historical `S/...` layout to `tenants/S/...`. Files under historical `default/...` must be classified explicitly: move tenant-owned files to the corresponding `tenants/S/...` namespace and global files to the application's separate `global/...` service. Do not infer ownership from the directory name.

The bundle storage interface does not expose a move operation. Perform migration with an offline, audited application command that maps every source tenant explicitly, rejects links and traversal, verifies checksums, and leaves a rollback copy.

## Cache

Enabled tenant-aware cache services now fail without a tenant and use hashed tenant namespaces. Existing cache entries are intentionally not reused; clear them during deployment. Generic PSR-6 and PSR-16 tenant decorators reject global `clear()` because it cannot be safely scoped.

## Mailer

Previously, templated email could add `X-Tenant-ID` and `X-Tenant-Name` automatically. Both headers are now disabled by default.

Opt in independently:

```yaml
zhortein_multi_tenant:
    mailer:
        add_tenant_id_header: true
        add_tenant_name_header: false
```

The bundle rejects unsafe header values and preserves an application-provided value. Review downstream systems before enabling either public header.

## Observability

Failure reasons, arbitrary context, raw error messages, and rejected header names are no longer copied into default logs or metric labels. Resolver and status labels remain bounded. Tenant identifiers may appear in access-controlled logs but never in default metric labels.
