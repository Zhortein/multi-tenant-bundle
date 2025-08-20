# Resolver Chain

The resolver chain allows you to configure multiple tenant resolution strategies that are tried in a specific order. This provides flexibility and fallback mechanisms for tenant resolution in complex multi-tenant applications.

> 📖 **Navigation**: [← Tenant Resolution](tenant-resolution.md) | [Back to Documentation Index](index.md) | [Domain Resolvers →](domain-resolvers.md)

## Overview

The chain resolver iterates through configured resolvers in the specified order and returns the first successful resolution. It supports strict mode for error handling and provides comprehensive logging and diagnostics.

## Configuration

### Basic Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [subdomain, path, header, query]
        strict: true
        header_allow_list: ["X-Tenant-Id", "X-Tenant-Slug"]
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `order` | array | `[subdomain, path, header, query]` | Order of resolvers to try |
| `strict` | boolean | `true` | Enable strict mode (throws exceptions on failure/ambiguity) |
| `header_allow_list` | array | `[X-Tenant-Id, X-Tenant-Slug]` | Allowed header names for header resolvers |

### Individual Resolver Configuration

Each resolver in the chain can be configured independently:

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [subdomain, header, query, path]
        strict: false
        header_allow_list: ["X-Tenant-Id"]
    
    # Configure individual resolvers
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api', 'admin']
    
    header:
        name: 'X-Tenant-Id'
    
    query:
        parameter: 'tenant'
    
    # Path resolver uses default configuration
```

## Available Resolvers

### 1. Subdomain Resolver (`subdomain`)
Resolves tenant from subdomain (e.g., `tenant1.myapp.com`)

### 2. Path Resolver (`path`)
Resolves tenant from URL path (e.g., `/tenant1/dashboard`)

### 3. Header Resolver (`header`)
Resolves tenant from HTTP headers (e.g., `X-Tenant-Id: tenant1`)

### 4. Query Resolver (`query`)
Resolves tenant from query parameters (e.g., `?tenant=tenant1`)

### 5. Domain Resolver (`domain`)
Resolves tenant from full domain mapping

### 6. Hybrid Resolver (`hybrid`)
Combines domain and subdomain resolution strategies

### 7. DNS TXT Resolver (`dns_txt`)
Resolves tenant from DNS TXT records

## Strict vs Non-Strict Mode

### Strict Mode (`strict: true`)

In strict mode, the chain resolver:
- **Validates consensus**: All resolvers that return a result must agree on the same tenant
- **Throws exceptions**: On failure or ambiguity, throws dedicated exceptions
- **Provides diagnostics**: Includes detailed diagnostic information in exceptions

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        strict: true  # Enable strict mode
```

**Behavior:**
- If no resolvers return a result → `TenantResolutionException`
- If resolvers return different tenants → `AmbiguousTenantResolutionException`
- If all resolvers agree → Returns the tenant

### Non-Strict Mode (`strict: false`)

In non-strict mode, the chain resolver:
- **Returns first match**: Stops at the first resolver that returns a tenant
- **Ignores failures**: Continues to next resolver if one fails
- **Returns null**: If no resolver succeeds

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        strict: false  # Disable strict mode
```

## Header Allow-List

The header allow-list provides security by restricting which HTTP headers can be used for tenant resolution:

```yaml
zhortein_multi_tenant:
    resolver_chain:
        header_allow_list: 
            - "X-Tenant-Id"
            - "X-Custom-Tenant"
```

**Behavior:**
- Header resolvers with names not in the allow-list are skipped
- Empty allow-list means all headers are allowed
- Provides protection against header injection attacks

## Exception Handling

### Development Mode

In development (`dev` or `test` environment), exceptions include full diagnostic information:

```json
{
    "error": "Multiple tenant resolution strategies returned different results",
    "code": 400,
    "type": "ambiguous_resolution",
    "diagnostics": {
        "resolvers_tried": [
            {
                "name": "subdomain",
                "result": "tenant1",
                "class": "Zhortein\\MultiTenantBundle\\Resolver\\SubdomainTenantResolver"
            },
            {
                "name": "header",
                "result": "tenant2",
                "class": "Zhortein\\MultiTenantBundle\\Resolver\\HeaderTenantResolver"
            }
        ],
        "resolvers_skipped": [],
        "strict_mode": true
    },
    "exception_message": "Ambiguous tenant resolution: resolvers subdomain, header returned different tenants: tenant1, tenant2"
}
```

### Production Mode

In production, exceptions return minimal information:

```json
{
    "error": "Multiple tenant resolution strategies returned different results",
    "code": 400
}
```

## Logging and Metrics

The chain resolver provides comprehensive logging:

### Success Logging

```
[INFO] Tenant resolved by chain resolver
{
    "resolver": "subdomain",
    "tenant_slug": "tenant1",
    "tenant_id": 123,
    "position_in_chain": 0
}
```

### Failure Logging

```
[WARNING] Resolver threw exception
{
    "resolver": "header",
    "exception": "Header not found",
    "exception_class": "RuntimeException"
}
```

### Skip Logging

```
[DEBUG] Header resolver skipped due to allow-list
{
    "resolver": "header",
    "header_name": "X-Custom-Header",
    "allow_list": ["X-Tenant-Id"]
}
```

## Usage Examples

### Example 1: Subdomain with Header Fallback

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [subdomain, header]
        strict: false
        header_allow_list: ["X-Tenant-Id"]
    
    subdomain:
        base_domain: 'myapp.com'
    
    header:
        name: 'X-Tenant-Id'
```

**Behavior:**
1. Try subdomain resolution first
2. If no subdomain tenant found, try header
3. Return first successful result

### Example 2: Strict Multi-Strategy Validation

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [header, query]
        strict: true
        header_allow_list: ["X-Tenant-Id"]
    
    header:
        name: 'X-Tenant-Id'
    
    query:
        parameter: 'tenant'
```

**Behavior:**
1. Both header and query must agree on the same tenant
2. Throws exception if they disagree or if neither finds a tenant
3. Provides detailed diagnostics on failure

### Example 3: API with Multiple Resolution Methods

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [header, query, path]
        strict: false
        header_allow_list: ["X-Tenant-Id", "Authorization"]
    
    header:
        name: 'X-Tenant-Id'
    
    query:
        parameter: 'tenant_id'
```

**Use Cases:**
- API clients can use `X-Tenant-Id` header
- Web clients can use `?tenant_id=` parameter
- Legacy URLs can use path-based resolution

## Best Practices

### 1. Order Resolvers by Reliability

Place most reliable resolvers first:

```yaml
resolver_chain:
    order: [subdomain, path, header, query]  # subdomain most reliable
```

### 2. Use Strict Mode for Critical Applications

Enable strict mode for applications requiring high tenant isolation:

```yaml
resolver_chain:
    strict: true
```

### 3. Restrict Header Allow-List

Limit allowed headers for security:

```yaml
resolver_chain:
    header_allow_list: ["X-Tenant-Id"]  # Only allow specific headers
```

### 4. Monitor Logs

Set up monitoring for resolution failures:

```bash
# Monitor for ambiguous resolutions
tail -f var/log/prod.log | grep "Ambiguous tenant resolution"

# Monitor for resolution failures
tail -f var/log/prod.log | grep "No tenant could be resolved"
```

### 5. Test Resolution Scenarios

Test different combinations of resolution methods:

```php
// Test ambiguous resolution
$request = new Request(['tenant' => 'tenant1']);
$request->headers->set('X-Tenant-Id', 'tenant2');

// Test fallback resolution
$request = Request::create('http://www.example.com/page?tenant=tenant1');
```

## Troubleshooting

### Common Issues

1. **Ambiguous Resolution**: Multiple resolvers return different tenants
   - **Solution**: Check request data consistency or disable strict mode

2. **No Tenant Resolved**: No resolver finds a tenant
   - **Solution**: Verify request format and resolver configuration

3. **Header Blocked**: Header resolver skipped due to allow-list
   - **Solution**: Add header name to `header_allow_list`

4. **Wrong Resolution Order**: Unexpected resolver takes precedence
   - **Solution**: Adjust `order` configuration

### Debug Commands

```bash
# Check resolver configuration
bin/console debug:container zhortein_multi_tenant.resolver

# Test tenant resolution
bin/console tenant:resolve --request-uri="/tenant1/page" --header="X-Tenant-Id: tenant1"
```

### Log Analysis

Enable debug logging to see detailed resolution process:

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        main:
            level: debug
```

The chain resolver provides a powerful and flexible way to handle tenant resolution in complex multi-tenant applications while maintaining security and providing comprehensive diagnostics.

---

> 📖 **Navigation**: [← Tenant Resolution](tenant-resolution.md) | [Back to Documentation Index](index.md) | [Domain Resolvers →](domain-resolvers.md)