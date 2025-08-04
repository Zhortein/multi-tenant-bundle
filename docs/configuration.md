# Configuration Reference

This document provides a complete reference for configuring the Zhortein Multi-Tenant Bundle.

## Minimal Configuration

Here's the minimal configuration required for the bundle:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver:
        type: 'subdomain'  # or 'path', 'header', 'custom'
```

The bundle will automatically:

* Inject the corresponding resolver
* Register a listener that resolves the tenant at the beginning of each request
* Expose a TenantContext injectable via autowiring

## Complete Configuration Reference

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Tenant entity class (required)
    tenant_entity: 'App\Entity\Tenant'
    
    # Tenant resolution strategy
    resolver:
        type: 'subdomain'  # 'subdomain', 'path', 'header', or 'custom'
        options:
            # Subdomain resolver options
            base_domain: 'example.com'
            excluded_subdomains: ['www', 'api', 'admin']
            
            # Path resolver options
            path_position: 1  # Position in URL path (0-based)
            
            # Header resolver options
            header_name: 'X-Tenant-Slug'
            
            # Custom resolver service
            service: 'app.custom_tenant_resolver'
    
    # Database strategy
    database:
        strategy: 'shared_db'  # 'shared_db' or 'multi_db'
        enable_filter: true
        connection_prefix: 'tenant_'  # For multi_db strategy
    
    # Default tenant (optional)
    default_tenant: null
    
    # Require tenant for all requests
    require_tenant: true
    
    # Cache configuration
    cache:
        pool: 'cache.app'
        ttl: 3600
    
    # Service integrations
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
    
    messenger:
        enabled: true
        fallback_dsn: 'sync://'
    
    storage:
        enabled: true
        type: 'local'  # 'local', 's3', or 'custom'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
    
    # Settings system
    settings:
        enabled: true
        cache_ttl: 3600
        fallback_values:
            theme: 'default'
            timezone: 'UTC'
    
    # Event listeners
    listeners:
        request_listener: true
        doctrine_filter_listener: true
        tenant_switch_listener: true
    
    # Fixtures
    fixtures:
        enabled: true
        auto_tenant_assignment: true
```

## Resolver Configuration

### Subdomain Resolver

```yaml
zhortein_multi_tenant:
    resolver:
        type: 'subdomain'
        options:
            base_domain: 'example.com'
            excluded_subdomains: ['www', 'api', 'admin', 'mail']
```

**How it works:**
- `tenant.example.com` → tenant slug: `tenant`
- `www.example.com` → no tenant (excluded)
- `example.com` → no tenant (base domain)

### Path Resolver

```yaml
zhortein_multi_tenant:
    resolver:
        type: 'path'
        options:
            path_position: 1  # Second segment (0-based)
```

**How it works:**
- `/tenant/products` → tenant slug: `tenant`
- `/api/tenant/users` → tenant slug: `tenant` (if path_position: 1)

### Header Resolver

```yaml
zhortein_multi_tenant:
    resolver:
        type: 'header'
        options:
            header_name: 'X-Tenant-Slug'
```

**How it works:**
- HTTP header `X-Tenant-Slug: acme` → tenant slug: `acme`

### Custom Resolver

```yaml
zhortein_multi_tenant:
    resolver:
        type: 'custom'
        options:
            service: 'app.custom_tenant_resolver'
```

Your custom resolver must implement `TenantResolverInterface`.

## Database Strategies

### Shared Database

```yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        enable_filter: true
```

All tenants share the same database with automatic filtering by `tenant_id`.

### Multi-Database

```yaml
zhortein_multi_tenant:
    database:
        strategy: 'multi_db'
        connection_prefix: 'tenant_'
```

Each tenant has its own database connection (e.g., `tenant_acme`, `tenant_demo`).

## Service Integration

### Mailer Configuration

```yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
        fallback_dsn: '%env(MAILER_DSN)%'
```

Tenants can override mailer settings via the settings system:
- `mailer_dsn`: Custom SMTP configuration
- `email_from`: From email address
- `email_sender`: Sender name

### Messenger Configuration

```yaml
zhortein_multi_tenant:
    messenger:
        enabled: true
        fallback_dsn: 'sync://'
```

Tenants can have custom message transports via settings.

### Storage Configuration

```yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: 'local'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
```

Files are automatically isolated per tenant in subdirectories.

## Environment-Specific Configuration

### Development

```yaml
# config/packages/dev/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    require_tenant: false  # Allow requests without tenant
    cache:
        ttl: 60  # Shorter cache for development
```

### Production

```yaml
# config/packages/prod/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    require_tenant: true
    cache:
        ttl: 3600  # Longer cache for production
    settings:
        cache_ttl: 7200  # Cache settings longer
```

### Testing

```yaml
# config/packages/test/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    require_tenant: false
    resolver:
        type: 'header'  # Easier to control in tests
        options:
            header_name: 'X-Tenant-Slug'
```

## Doctrine Configuration

The bundle requires Doctrine filter configuration:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        filters:
            tenant_filter:
                class: Zhortein\MultiTenantBundle\Doctrine\Filter\TenantFilter
                enabled: true
```

## Advanced Configuration

### Custom Services

```yaml
# config/services.yaml
services:
    # Custom tenant resolver
    app.custom_tenant_resolver:
        class: App\Resolver\CustomTenantResolver
        arguments:
            - '@zhortein_multi_tenant.registry'
        tags:
            - { name: 'zhortein_multi_tenant.resolver' }
    
    # Custom storage adapter
    app.custom_storage:
        class: App\Storage\CustomStorageAdapter
        tags:
            - { name: 'zhortein_multi_tenant.storage' }
```

### Performance Tuning

```yaml
zhortein_multi_tenant:
    cache:
        pool: 'cache.redis'  # Use Redis for better performance
        ttl: 7200
    
    settings:
        cache_ttl: 14400  # Cache settings longer
        
    database:
        enable_filter: true  # Always enable for performance
```

## Validation

You can validate your configuration using:

```bash
# Check configuration
php bin/console debug:config zhortein_multi_tenant

# Validate tenant setup
php bin/console tenant:validate

# Test tenant resolution
php bin/console tenant:list
```
