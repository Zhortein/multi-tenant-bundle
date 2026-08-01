# Tenant-Aware Decorators

## Cache isolation

Enabled tenant-aware cache decorators require an active tenant. Missing context throws `TenantCacheException`; it never exposes unprefixed/global entries. Tenant identifiers are represented by a SHA-256 namespace, so crafted identifiers cannot forge another tenant prefix and identifiers do not appear in backend cache keys.

The Symfony adapter decorator supports tenant-scoped `clear()`. Generic PSR-6 and PSR-16 pools cannot guarantee prefix-scoped clearing, so their enabled decorators reject `clear()`; delete explicit keys or use the Symfony adapter decorator. Items passed to the PSR-6 decorator must have been obtained from that same decorator.

For legitimate global cache data, inject a separate undecorated cache pool explicitly.

## Storage path helper

`TenantStoragePathHelper` produces `tenants/{identifier}/...` paths and uses the same strict validation as tenant storage. Every enabled operation requires an active tenant. Only `/` is supported as a separator; ambiguous custom separators are rejected. Disabled helpers are an explicit application configuration and do not infer global behavior from missing context.

```php
$path = $pathHelper->prefixPath('uploads/document.pdf', useSlug: true);
// tenants/acme/uploads/document.pdf
```

## Logging

`TenantLoggerProcessor` adds tenant identifiers to structured log records. Logs are access-controlled diagnostic data; do not treat them as public output. See [Observability](observability.md) for privacy and cardinality rules.
