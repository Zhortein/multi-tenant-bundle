# Migrating from RC1 to RC2

RC2 intentionally removes RC1's implicit global fallbacks.

## Required application changes

Implement exactly one of `TenantAwareMessageInterface` or `GlobalMessageInterface` on every dispatched application message. Wrap third-party messages that cannot implement these interfaces. Tenant-aware dispatch now requires a current tenant; received tenant-aware messages require a valid `TenantStamp` resolving to an available tenant. Global messages must not carry tenant stamps.

Ensure every tenant-aware ORM read, persist, update, and removal has an established tenant context. Existing entity ownership must match that context and tenant ownership cannot be changed. Replace manual Doctrine filter disabling with an application-authorized call to `GlobalDoctrineScopeInterface::run()`.

## Exception categories

- Programming errors: `MissingTenantContextException`, `InvalidTenantIdentifierException`, `InvalidTenantMappingException`, `TenantMismatchException`, `UnclassifiedMessageException`, and `GlobalDoctrineScopeException` for nesting.
- Configuration/runtime protection failures: `DoctrineProtectionException` and suspension/restoration forms of `GlobalDoctrineScopeException`.
- Non-treatable messages: `MissingTenantStampException`, `InvalidTenantIdentifierException`, `UnknownTenantException`, `TenantMismatchException`, and `UnclassifiedMessageException`.
- Retry policy: registry, connection, or transport infrastructure failures may be retryable. Unknown/deleted tenants and malformed or contradictory metadata are normally terminal. Applications must configure Messenger retry/failure transports accordingly.

## Boundaries

The ORM filter is not the only isolation barrier and does not intercept DQL bulk update/delete, direct DBAL, native SQL, migrations, or operations with listeners/filters disabled. RLS remains optional PostgreSQL defense in depth. Administrative and migration paths require separate, explicit authorization and review.
## Doctrine lifecycle

RC2 enables `tenant_filter` when every EntityManager is created. Missing tenant
context is no longer equivalent to an unfiltered/global query: tenant-aware ORM
access throws `MissingTenantContextException`. Run administrative global ORM work
through `GlobalDoctrineScopeInterface` and flush it before leaving the scope.

Context changes reject EntityManagers with scheduled or detected unflushed
insertions, updates, deletions, or collection changes. RC2 never flushes or clears
a dirty EntityManager automatically. A successful transition clears clean identity
maps, so applications must not retain managed entities across tenant transitions.

`TenantConnectionResolverInterface` was removed because it cannot represent
global, no-context, preparation, rollback, and cleanup states. Multi-database
integrations provide a `TenantConnectionParametersProviderInterface`; the bundle's
`DoctrineTenantConnectionLifecycle` performs the reversible transition. A missing
provider makes the container invalid instead of falling back to the last tenant
connection.

## Messenger

When Messenger support is enabled, RC2 installs classification and propagation
middleware on every declared bus. Every application message must implement exactly
one of `TenantAwareMessageInterface` or `GlobalMessageInterface`.

## Authentication model

Authenticatable identities should be global. Represent tenant access with filtered
membership/business entities, select the tenant after authentication, and authorize
the membership separately. Do not disable the Doctrine filter in a user provider.
