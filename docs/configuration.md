# Configuration Reference

## Canonical resolver syntax

The `resolver` option is a scalar. Supported values are `path`, `subdomain`, `header`, `query`, `domain`, `hybrid`, `dns_txt`, `chain`, and `custom`.

~~~yaml
zhortein_multi_tenant:
    resolver: 'path'
~~~

Older examples using `resolver.type` or `resolution.strategy` were documentation errors and never matched the effective configuration tree. They are not compatibility aliases and are rejected with an actionable configuration error.

`listeners.request_listener` controls automatic early HTTP resolution only.
Setting it to `false` does not disable the unconditional main-request reset
barrier. Use the public `TenantRequestContextLoaderInterface` for explicit
post-authentication resolution. See [Tenant Resolution](tenant-resolution.md)
and [RC4 to RC5 Migration](migration-rc4-to-rc5.md).


This document provides a complete reference for configuring the Zhortein Multi-Tenant Bundle.

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Installation Guide →](installation.md)

## Minimal Configuration

Here's the minimal configuration required for the bundle:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'subdomain'  # Available: path, subdomain, header, query, domain, hybrid, dns_txt, chain, custom
```

The bundle will automatically:

* Inject the corresponding resolver
* Register a listener that resolves the tenant at the beginning of each request
* Expose a TenantContext injectable via autowiring
* Enable Doctrine tenant filtering (for shared_db strategy)

For tenant-aware ORM entities, missing context, invalid identifiers, invalid mappings, and filter activation failures throw dedicated exceptions instead of producing an unfiltered query. `require_tenant` still controls request resolution policy; it does not weaken these per-operation fail-closed boundaries. Global ORM work uses `GlobalDoctrineScopeInterface::run()` and remains subject to application authorization.

## Complete Configuration Reference

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Tenant entity class (required)
    tenant_entity: 'App\Entity\Tenant'
    
    # Tenant resolution strategy (required)
    resolver: 'subdomain'  # Available: path, subdomain, header, query, domain, hybrid, dns_txt, chain, custom
    
    # Resolver chain configuration (when resolver: 'chain')
    resolver_chain:
        order: ['subdomain', 'path', 'header', 'query']  # Order of resolvers to try
        strict: true  # Whether to use strict mode (throw exceptions on failure/ambiguity)
        header_allow_list: ['X-Tenant-Id', 'X-Tenant-Slug']  # Allowed header names for header resolvers
    
    # Default tenant slug (optional)
    default_tenant: null
    
    # Require tenant for all requests
    require_tenant: false
    
    # Subdomain resolver configuration
    subdomain:
        base_domain: 'localhost'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp']
    
    # Header resolver configuration
    header:
        name: 'X-Tenant-Slug'
    
    # Query resolver configuration
    query:
        parameter: 'tenant'
    
    # Domain resolver configuration
    domain:
        domain_mapping:
            'tenant-one.com': 'tenant_one'
            'tenant-two.com': 'tenant_two'
    
    # Hybrid resolver configuration
    hybrid:
        domain_mapping:
            'acme-client.com': 'acme'
        subdomain_mapping:
            '*.my_platform.com': 'use_subdomain_as_slug'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static']
    
    # DNS TXT resolver configuration
    dns_txt:
        timeout: 5  # DNS query timeout in seconds (1-30)
        enable_cache: true  # Whether to enable DNS result caching
    
    # Database configuration
    database:
        strategy: 'shared_db'  # 'shared_db' or 'multi_db'
        enable_filter: true  # Enable Doctrine tenant filter (shared_db only)
        auto_tenant_id: true  # Automatically add tenant_id to entities (shared_db only)
        connection_prefix: 'tenant_'  # Connection name prefix for multi_db mode
        rls:
            enabled: false  # Enable PostgreSQL Row-Level Security (shared_db only)
            session_variable: 'app.tenant_id'  # PostgreSQL session variable name
            policy_name_prefix: 'tenant_isolation'  # Prefix for RLS policy names
    
    # Cache configuration
    cache:
        pool: 'cache.app'  # Cache pool service to use
        ttl: 3600  # Cache TTL in seconds
    
    # Mailer configuration
    mailer:
        enabled: true  # Enable tenant-aware mailer
        add_tenant_id_header: false  # Explicit opt-in for X-Tenant-ID
        add_tenant_name_header: false  # Independent opt-in for X-Tenant-Name
        fallback_dsn: null  # Fallback mailer DSN
        fallback_from: null  # Fallback from address
        fallback_sender: null  # Fallback sender name
    
    # Messenger configuration
    messenger:
        enabled: true  # Enable tenant-aware messenger
        fallback_dsn: 'sync://'  # Fallback transport DSN
        fallback_bus: 'messenger.bus.default'  # Fallback messenger bus
        default_transport: 'async'  # Default transport name
        add_tenant_headers: true  # Add tenant information to message headers
        tenant_transport_map: {}  # Mapping of tenant slugs to transport names
    
    # Fixtures configuration
    fixtures:
        enabled: true  # Enable tenant-aware fixtures loading
    
    # Events configuration
    events:
        dispatch_database_switch: true  # Dispatch events when switching tenant databases
    
    # Storage configuration
    storage:
        enabled: true  # Enable tenant-aware storage
        type: 'local'  # Storage type: 'local', 's3', or 'custom'
        base_path: '%kernel.project_dir%/var/tenant_storage'  # Base path for local storage
        base_url: '/tenant-files'  # Base URL for serving files
        s3:
            bucket: null  # S3 bucket name
            region: 'us-east-1'  # S3 region
            endpoint: null  # Custom S3 endpoint
            path_style: false  # Use path-style URLs
        custom:
            service: null  # Custom storage service ID
```

## Resolver Configuration Details

### Subdomain Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'subdomain'
    subdomain:
        base_domain: 'example.com'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp']
```

**How it works:**
- `tenant.example.com` → tenant slug: `tenant`
- `www.example.com` → no tenant (excluded)
- `example.com` → no tenant (base domain)

### Path Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'path'
```

**How it works:**
- `/tenant/products` → tenant slug: `tenant`
- `/api/tenant/users` → tenant slug: `api` (first path segment)

### Header Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'header'
    header:
        name: 'X-Tenant-Slug'
```

**How it works:**
- HTTP header `X-Tenant-Slug: acme` → tenant slug: `acme`

### Query Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'query'
    query:
        parameter: 'tenant'
```

**How it works:**
- `https://example.com/page?tenant=acme` → tenant slug: `acme`

### Domain Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            'acme-corp.com': 'acme'
            'beta-client.com': 'beta'
```

**How it works:**
- `https://acme-corp.com/page` → tenant slug: `acme`
- `https://beta-client.com/dashboard` → tenant slug: `beta`

### Hybrid Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            'acme-client.com': 'acme'
        subdomain_mapping:
            '*.my_platform.com': 'use_subdomain_as_slug'
        excluded_subdomains: ['www', 'api', 'admin']
```

**How it works:**
- `https://acme-client.com/page` → tenant slug: `acme` (domain mapping)
- `https://beta.my_platform.com/page` → tenant slug: `beta` (subdomain mapping)
- `https://www.my_platform.com/page` → no tenant (excluded)

### DNS TXT Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
```

**How it works:**
- Looks for TXT record: `_tenant.example.com` → `tenant-slug=acme`
- `https://example.com/page` → tenant slug: `acme`

### Resolver Chain

```yaml
zhortein_multi_tenant:
    resolver: 'chain'
    resolver_chain:
        order: ['subdomain', 'path', 'header', 'query']
        strict: true
        header_allow_list: ['X-Tenant-Id', 'X-Tenant-Slug']
```

**How it works:**
- Tries resolvers in order until one succeeds
- In strict mode: throws exception if multiple resolvers return different tenants
- In non-strict mode: returns first successful resolution

### Custom Resolver

```yaml
zhortein_multi_tenant:
    resolver: 'custom'
```

Then register your custom resolver service:

```yaml
# config/services.yaml
services:
    App\Resolver\CustomTenantResolver: ~

    Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface:
        alias: App\Resolver\CustomTenantResolver
```

Your custom resolver must implement `TenantResolverInterface`. The bundle does not collect tagged custom resolvers.

## Database Strategies

### Shared Database Strategy

```yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        enable_filter: true
        auto_tenant_id: true
        rls:
            enabled: false  # Enable for defense-in-depth
            session_variable: 'app.tenant_id'
            policy_name_prefix: 'tenant_isolation'
```

**Features:**
- All tenants share the same database
- Automatic filtering by `tenant_id` column
- Entities automatically get `tenant_id` when `auto_tenant_id: true`
- Optional PostgreSQL Row-Level Security (RLS) for additional protection

### Multi-Database Strategy

```yaml
zhortein_multi_tenant:
    database:
        strategy: 'multi_db'
        connection_prefix: 'tenant_'
```

**Features:**
- Each tenant has its own database connection
- Connection names: `tenant_acme`, `tenant_demo`, etc.
- No `tenant_id` column needed
- Complete data isolation at database level

### PostgreSQL Row-Level Security (RLS)

```yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        rls:
            enabled: true
            session_variable: 'app.tenant_id'
            policy_name_prefix: 'tenant_isolation'
```

**Benefits:**
- Defense-in-depth security
- Database-level tenant isolation
- Remains effective in dedicated defense-in-depth tests that bypass the Doctrine filter; application code must use the explicit global Doctrine scope
- Automatic policy creation for tenant-aware entities

## Service Integration

### Mailer Configuration

```yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'My App'
```

**Tenant-specific settings** (via TenantSettingsManager):
- `mailer_dsn`: Custom SMTP configuration
- `mailer_from`: From email address
- `mailer_sender`: Sender name

### Messenger Configuration

```yaml
zhortein_multi_tenant:
    messenger:
        enabled: true
        fallback_dsn: 'sync://'
        fallback_bus: 'messenger.bus.default'
        default_transport: 'async'
        add_tenant_headers: true
        tenant_transport_map:
            'premium_tenant': 'high_priority'
            'basic_tenant': 'low_priority'
```

**Features:**
- Automatic tenant context propagation in messages
- Tenant-specific transport routing
- Tenant information in message headers/stamps

### Storage Configuration

```yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: 'local'  # 'local', 's3', or 'custom'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
        s3:
            bucket: 'my-tenant-files'
            region: 'us-east-1'
            endpoint: null
            path_style: false
```

**Features:**
- Tenant-isolated file storage
- Support for local filesystem and S3
- Automatic path prefixing by tenant slug

## Environment Variables

Common environment variables used in configuration:

```bash
# Database
DATABASE_URL=postgresql://user:pass@localhost:5432/app_db

# Mailer fallback
MAILER_DSN=smtp://localhost:1025

# S3 Storage (if using S3)
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1

# Cache
REDIS_URL=redis://localhost:6379
```

## Configuration Examples

### Production Multi-Tenant SaaS

```yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'subdomain'
    require_tenant: true
    
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api', 'admin', 'app']
    
    database:
        strategy: 'shared_db'
        enable_filter: true
        auto_tenant_id: true
        rls:
            enabled: true  # Defense-in-depth
    
    cache:
        pool: 'cache.redis'
        ttl: 7200
    
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
    
    messenger:
        enabled: true
        add_tenant_headers: true
        tenant_transport_map:
            'enterprise': 'high_priority'
            'premium': 'medium_priority'
    
    storage:
        enabled: true
        type: 's3'
        s3:
            bucket: 'myapp-tenant-files'
            region: 'us-east-1'
```

### Development Environment

```yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'chain'
    require_tenant: false
    default_tenant: 'demo'
    
    resolver_chain:
        order: ['header', 'query', 'subdomain']
        strict: false
    
    subdomain:
        base_domain: 'localhost'
    
    header:
        name: 'X-Tenant-Slug'
    
    query:
        parameter: 'tenant'
    
    database:
        strategy: 'shared_db'
        enable_filter: true
        rls:
            enabled: false  # Disabled for easier debugging
    
    storage:
        enabled: true
        type: 'local'
        base_path: '%kernel.project_dir%/var/dev_tenant_storage'
```

## Next Steps

- [Installation Guide](installation.md) - Set up the bundle
- [Tenant Resolution](tenant-resolution.md) - Learn about resolution strategies
- [Database Strategies](database-strategies.md) - Choose your database approach
- [Examples](examples/) - See practical implementations

---

> 📖 **Navigation**: [← Back to Documentation Index](index.md) | [Installation Guide →](installation.md)
