# Tenant-Aware Decorators

## Cache isolation

Enabled tenant-aware cache decorators require an active tenant. Missing context throws `TenantCacheException`; it never exposes unprefixed/global entries. Tenant identifiers are represented by a SHA-256 namespace, so crafted identifiers cannot forge another tenant prefix and identifiers do not appear in backend cache keys.

The default Symfony integration decorates `cache.app`. The configured pool must implement PSR-6 `Psr\Cache\CacheItemPoolInterface`; Symfony cache pools additionally retain `Symfony\Contracts\Cache\CacheInterface`, `Symfony\Contracts\Cache\NamespacedPoolInterface`, and `Symfony\Component\Cache\Adapter\AdapterInterface`. These public contracts are verified on Symfony 7.4, 8.0, and 8.1. No internal FrameworkBundle service is injected.

```yaml
framework:
    cache:
        app: cache.adapter.filesystem
        pools:
            cache.global:
                adapter: cache.adapter.filesystem

zhortein_multi_tenant:
    decorators:
        cache:
            enabled: true
            services: [cache.app]
```

To decorate a custom Symfony pool, define it under `framework.cache.pools` and list its service ID under `decorators.cache.services`. Every listed service must exist and satisfy the PSR-6 pool contract. Set `enabled: false` when the optional integration is not used; the bundle then registers no tenant-aware cache decorator.

The Symfony adapter decorator supports tenant-scoped `clear()`. Generic PSR-6 and PSR-16 pools cannot guarantee prefix-scoped clearing, so their enabled decorators reject `clear()`; delete explicit keys or use the Symfony adapter decorator. Items passed to the PSR-6 decorator must have been obtained from that same decorator.

For legitimate global cache data, inject a separate undecorated cache pool explicitly, such as `cache.global` above. Tenant-aware pools never infer global scope from an absent context. Consumer sub-namespaces are composed inside the tenant namespace, so they cannot cross tenant boundaries.

The RC4 correction is not a consumer API break: it restores a Symfony contract already advertised by `cache.app`. Cache keys, tenant hashing, missing-context failures, and explicit global-pool behavior remain compatible with RC3.

RC5 additionally implements Symfony's `ResetInterface` on the adapter
decorator. Its `reset()` is intentionally idempotent and does not delegate to
the decorated pool because Symfony resets that pool independently. No tenant
namespace is cached: it is recomputed from the current context for every
operation, including immutable `withSubNamespace()` derivatives. The real
`services_resetter` is tested after `cache.app` initialization.

## Storage path helper

`TenantStoragePathHelper` produces `tenants/{identifier}/...` paths and uses the same strict validation as tenant storage. Every enabled operation requires an active tenant. Only `/` is supported as a separator; ambiguous custom separators are rejected. Disabled helpers are an explicit application configuration and do not infer global behavior from missing context.

```php
$path = $pathHelper->prefixPath('uploads/document.pdf', useSlug: true);
// tenants/acme/uploads/document.pdf
```

## Logging

`TenantLoggerProcessor` adds tenant identifiers to structured log records. Logs are access-controlled diagnostic data; do not treat them as public output. See [Observability](observability.md) for privacy and cardinality rules.
