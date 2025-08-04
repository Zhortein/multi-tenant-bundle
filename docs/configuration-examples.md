# Configuration Examples

This document provides comprehensive configuration examples for the Zhortein Multi-Tenant Bundle.

## Basic Configuration

### Minimal Setup (Path-based)

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'path'
```

### Subdomain-based Setup

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'subdomain'
    
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api', 'admin', 'mail']
```

## Advanced Configuration

### Full Configuration with All Options

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Core settings
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'subdomain'
    default_tenant: 'default'
    require_tenant: false
    
    # Subdomain resolver settings
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp', 'cdn']
    
    # Database configuration
    database:
        strategy: 'shared'  # or 'separate'
        enable_filter: true
    
    # Cache configuration
    cache:
        pool: 'cache.app'
        ttl: 3600  # 1 hour
    
    # Service integrations
    mailer:
        enabled: true
    messenger:
        enabled: true
    
    # Event listeners
    listeners:
        request_listener: true
        doctrine_filter_listener: true
```

### Production Configuration

```yaml
# config/packages/prod/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'subdomain'
    require_tenant: true  # Strict mode in production
    
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static']
    
    cache:
        pool: 'cache.redis'  # Use Redis in production
        ttl: 7200  # 2 hours
    
    listeners:
        request_listener: true
        doctrine_filter_listener: true
```

### Development Configuration

```yaml
# config/packages/dev/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: 'App\Entity\Tenant'
    resolver: 'path'  # Easier for local development
    default_tenant: 'demo'  # Default tenant for development
    require_tenant: false
    
    cache:
        pool: 'cache.app'
        ttl: 60  # Short TTL for development
    
    listeners:
        request_listener: true
        doctrine_filter_listener: true
```

## Doctrine Configuration

### Basic Doctrine Setup

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        
    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true
        report_fields_where_declared: true
        validate_xml_mapping: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        
        # Enable tenant filter
        filters:
            tenant:
                class: Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
                enabled: true
        
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

### Multi-Database Setup (Separate Databases per Tenant)

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            tenant_template:
                url: '%env(resolve:TENANT_DATABASE_URL)%'
                
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings:
                    App:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
            tenant:
                connection: tenant_template
                mappings:
                    TenantData:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity/Tenant'
                        prefix: 'App\Entity\Tenant'
                        alias: TenantData
```

## Service Configuration

### Custom Tenant Resolver

```yaml
# config/services.yaml
services:
    App\Resolver\HeaderTenantResolver:
        arguments:
            $tenantRegistry: '@Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface'
        tags:
            - { name: 'zhortein.tenant_resolver' }

# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'custom'  # Use custom resolver
```

### Custom Cache Pool

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            tenant_settings_cache:
                adapter: cache.adapter.redis
                default_lifetime: 3600
                
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    cache:
        pool: 'tenant_settings_cache'
        ttl: 3600
```

## Environment Variables

### .env Configuration

```bash
# Database
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/myapp?serverVersion=16&charset=utf8"

# For separate tenant databases
TENANT_DATABASE_URL="postgresql://user:password@127.0.0.1:5432/tenant_{tenant_id}?serverVersion=16&charset=utf8"

# Redis (for production caching)
REDIS_URL="redis://localhost:6379"

# Multi-tenant specific
TENANT_BASE_DOMAIN="myapp.com"
TENANT_DEFAULT_SLUG="demo"
```

### .env.local (Development)

```bash
# Override for local development
DATABASE_URL="postgresql://postgres:password@localhost:5432/myapp_dev?serverVersion=16&charset=utf8"
TENANT_BASE_DOMAIN="localhost:8000"
```

## Security Configuration

### CORS for Multi-Tenant API

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['~^https?://([\w-]+\.)?myapp\.com$']
        allow_methods: ['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE']
        allow_headers: ['Content-Type', 'Authorization', 'X-Tenant-Slug']
        expose_headers: ['Link']
        max_age: 3600
```

### Security Configuration

```yaml
# config/packages/security.yaml
security:
    providers:
        tenant_user_provider:
            entity:
                class: App\Entity\User
                property: email
                
    firewalls:
        main:
            lazy: true
            provider: tenant_user_provider
            # ... other firewall configuration
```

## Routing Configuration

### Tenant-Aware Routes

```yaml
# config/routes.yaml
tenant_routes:
    resource: '../src/Controller/'
    type: attribute
    prefix: '/{tenant}'
    requirements:
        tenant: '[a-z0-9-]+'
    defaults:
        tenant: null
```

### API Routes

```yaml
# config/routes/api.yaml
api:
    resource: '../src/Controller/Api/'
    type: attribute
    prefix: '/api'
    
tenant_api:
    resource: '../src/Controller/Api/'
    type: attribute
    prefix: '/{tenant}/api'
    requirements:
        tenant: '[a-z0-9-]+'
```

## Messenger Configuration

### Tenant-Aware Message Handling

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    use_notify: true
                    check_delayed_interval: 60000
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
        
        routing:
            'App\Message\TenantAwareMessage': async
```

## Mailer Configuration

### Tenant-Specific Mailer

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        
# Custom configuration per tenant via TenantSettingsManager
# Settings keys: 'mailer.from_email', 'mailer.from_name', etc.
```

## Monitoring and Logging

### Monolog Configuration

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        main:
            type: fingers_crossed
            action_level: error
            handler: nested
            excluded_http_codes: [404, 405]
            buffer_size: 50
        nested:
            type: stream
            path: php://stderr
            level: debug
            formatter: monolog.formatter.json
        tenant:
            type: stream
            path: '%kernel.logs_dir%/tenant.log'
            level: info
            channels: ['tenant']
```

## Performance Optimization

### OPcache Configuration

```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.enable_file_override=1
```

### APCu Configuration

```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.apcu
        system: cache.adapter.system
```

This comprehensive configuration guide covers all aspects of setting up the multi-tenant bundle for different environments and use cases.