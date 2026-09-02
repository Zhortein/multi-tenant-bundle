# Persistent Process Lifecycle

`TenantContext` is a shared mutable service. It is not recreated for every
request, message, schedule execution, or command. RC5 therefore treats every
execution boundary as a transition to the explicit `NONE` state and registers
the complete context reset with Symfony's `kernel.reset` mechanism.

## Reset contract

`TenantContextInterface` implements
`Symfony\Contracts\Service\ResetInterface`. Its `reset()` method performs the
following deterministic sequence:

1. invalidate the logical tenant immediately;
2. invalidate active tenant scopes and restore any suspended global Doctrine
   filter;
3. reset Doctrine filter parameters and clear clean identity maps;
4. clear PostgreSQL RLS session state;
5. publish the multi-database router state as `NONE` and close managed DBAL
   connections;
6. dispatch the context-ended event after cleanup;
7. report all cleanup failure classes through a non-sensitive
   `TenantStateResetException`.

The sequence is idempotent. All participants are attempted when one fails.
`TenantStateResetterInterface` is the public orchestration facade used by HTTP,
Messenger, Console, Scheduler-style execution boundaries, and consumers that
need an explicit barrier.

The logical tenant becomes `NONE` before fallible I/O. A dirty or otherwise
untrustworthy EntityManager is never flushed. Its connection and manager are
closed, and `ManagerRegistry::resetManager()` is requested. A connection whose
RLS or routing cleanup failed is closed. The next execution cannot reuse the
old logical tenant or a resource that the bundle still considers safe.

If application work already failed and terminal cleanup also fails, the public
`TenantContextTransitionException` keeps the application exception as its
`previous` exception and exposes the separate cleanup failure through
`getCleanupFailure()`. Messages and public exception text contain no DSN,
password, SQL error, or connection parameters.

## Complete process-local state inventory

| Component | Mutable or derived state | Source of truth and scope | Boundary cleanup and failure behavior |
|---|---|---|---|
| `TenantContext` | Current `TenantInterface` | Shared service; authoritative logical state | Invalidated first; orchestrates all reset participants; `kernel.reset` entry point |
| `DoctrineTenantContextSynchronizer` / `TenantConnectionState` | `NONE`, `TENANT`, or `GLOBAL` mode | Shared service derived from context/scope | Publishes `NONE` first; checks every EntityManager; aggregates cleanup failures |
| Doctrine filters | Enabled/suspended flag, `tenant_context_mode`, `tenant_id` | Per EntityManager/FilterCollection | Restored if a global scope suspended it; set to `NONE` and `__NO_TENANT__` |
| EntityManager / UnitOfWork | Identity map, scheduled insertions, updates, deletions, and dirty collections | Per manager | Clean managers are cleared; dirty or closed managers are quarantined and reset; never flushed |
| DBAL connections | Open physical session, transaction/session settings | Per Doctrine connection | Closed for multi-db reset or whenever cleanliness cannot be guaranteed |
| PostgreSQL RLS | Session variable such as `app.tenant_id` | PostgreSQL backend session | Set to the empty fail-closed value for `NONE`; cleanup error closes the connection and remains observable |
| `DoctrineTenantConnectionRouter` | Selected tenant connection parameters | Shared multi-db routing state | Router publishes `NONE` before managed connections are closed |
| `TenantConnectionLifecycleInterface` implementations | Prepared transition and active routed connections | Transition-local plus shared router | Temporary probes are cleaned; reset closes managed connections; no previous route is restored at a boundary |
| `GlobalDoctrineScope` | `running`, `invalidated`, suspended filter collection | Shared service | Reset invalidates an active scope, restores filters, forces `NONE`; the callback cannot reopen global authorization |
| `TenantScope` | Current tenant and constructed tenant-scoped services | Shared container scope | `clearAll()` removes every scoped instance and current tenant |
| Tenant-aware cache decorator | Immutable consumer sub-namespace; tenant prefix computed per operation | Shared decorator, context is source | `reset()` exists and is idempotent; decorated Symfony pool is reset separately, avoiding a double reset |
| Tenant settings | Values in repositories and an external cache keyed by tenant | Persistent storage/cache, not an active-tenant selector | No process-local active tenant is retained; cache remains tenant-keyed and is independently reset by Symfony |
| Tenant registry | Doctrine repository/metadata references | Persistent database is source | No active tenant selection is cached by the bundle |
| Storage and mailer decorators | No active tenant property | Read the context for each operation | Fail closed without context; no boundary state to restore |
| Logger processor and metrics subscribers | No active tenant property | Read events/context for each record | No tenant selection survives a record/event |
| Resolver chain and built-in resolvers | Configuration and resolver list | Immutable shared services | No resolved tenant is cached as process state; `null` and exceptions leave the loader at `NONE` |
| Messenger sending middleware | Envelope-local stamp only | Envelope/context at dispatch | Does not own worker state |
| Messenger worker middleware | Handler-local classification | Received envelope is source | Resets at entry and in `finally`; never restores a pre-worker tenant |
| Console subscriber | Current command event and error-boundary flag | Console event is source | Resets before command and once on error or normal terminate; the flag is cleared by terminate or the next command |
| `TenantExecutionBoundaryInterface` | Callback-local result/failure | Caller-supplied operation | Resets before and after success or failure; intended for Scheduler and custom loops |
| DNS, configuration, factory, and compiler registries | Immutable configuration or framework-managed caches | Configuration/container metadata | They do not represent an active tenant; framework caches follow their normal reset contracts |

Persistent tenant data in a database, tenant-keyed cache entries, queued
envelopes, and immutable configuration are not active process-local state and
are not deleted by a lifecycle reset.

## Doctrine and UnitOfWork

A reset calls `computeChangeSets()` and checks scheduled entity insertions,
updates, deletions, collection updates, and collection deletions on every
EntityManager. It never calls `flush()`. A dirty manager makes the reset fail
explicitly after logical invalidation and quarantine; discarding unflushed
application work is never presented as successful cleanup. Closed managers are
also replaced through the registry. Managers created after a reset start from
the bundle's fail-closed filter configuration.

This contract applies to `shared_db`, `multi_db`, tenant, global, and `NONE`
transitions. Switching directly from A to B is rejected while any manager is
dirty.

## Boundary matrix

| Boundary | Entry | Normal/exceptional exit |
|---|---|---|
| Main HTTP request | Early `kernel.request` barrier, before automatic resolution | `kernel.terminate`; next main request and `kernel.reset` remain mandatory fallbacks |
| HTTP sub-request/fragment | No boundary reset | Inherits the main-request context without clearing it |
| Streamed response | Tenant remains available while `sendContent()` runs | Cleared only at `kernel.terminate` |
| Messenger received message | Reset before classification and tenant installation | Reset after success, rejection, handler exception, retry, or redelivery |
| Reused Console `Application` | Reset at `console.command` | Reset at `console.error` and `console.terminate` |
| Scheduler/custom loop | `TenantExecutionBoundaryInterface::run()` | Reset in the terminal path, preserving the operation failure |

If a runtime omits a terminal event, the next entry barrier prevents the stale
state from being reused.
