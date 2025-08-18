# Resolver Chain Usage Examples

This document provides practical examples of using the resolver chain feature in the Zhortein Multi-Tenant Bundle.

## Basic Configuration

### Example 1: Simple Chain with Fallback

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [subdomain, header, query]
        strict: false
        header_allow_list: ["X-Tenant-Id"]
    
    subdomain:
        base_domain: 'myapp.com'
        excluded_subdomains: ['www', 'api']
    
    header:
        name: 'X-Tenant-Id'
    
    query:
        parameter: 'tenant'
```

**Behavior:**
1. First tries subdomain resolution (e.g., `tenant1.myapp.com`)
2. If no subdomain tenant found, tries header `X-Tenant-Id`
3. If no header found, tries query parameter `?tenant=tenant1`
4. Returns first successful match or null if none found

### Example 2: Strict Mode for API Applications

```yaml
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [header, query]
        strict: true  # Requires all resolvers to agree
        header_allow_list: ["X-Tenant-Id", "Authorization"]
    
    header:
        name: 'X-Tenant-Id'
    
    query:
        parameter: 'tenant_id'
```

**Behavior:**
- Both header and query parameter must resolve to the same tenant
- Throws `AmbiguousTenantResolutionException` if they disagree
- Throws `TenantResolutionException` if neither finds a tenant

## Code Examples

### Example 3: Programmatic Usage

```php
<?php

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;

class TenantController
{
    public function __construct(
        private ChainTenantResolver $tenantResolver
    ) {}
    
    public function getCurrentTenant(Request $request): JsonResponse
    {
        try {
            $tenant = $this->tenantResolver->resolveTenant($request);
            
            if (!$tenant) {
                return new JsonResponse(['error' => 'No tenant found'], 404);
            }
            
            return new JsonResponse([
                'tenant_id' => $tenant->getId(),
                'tenant_slug' => $tenant->getSlug(),
                'tenant_name' => $tenant->getName(),
            ]);
            
        } catch (AmbiguousTenantResolutionException $e) {
            // In development, this includes diagnostics
            return new JsonResponse([
                'error' => 'Ambiguous tenant resolution',
                'diagnostics' => $e->getDiagnostics(),
            ], 400);
            
        } catch (TenantResolutionException $e) {
            return new JsonResponse([
                'error' => 'Tenant resolution failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
```

### Example 4: Custom Resolver in Chain

```php
<?php

use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class JwtTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private JwtDecoderInterface $jwtDecoder
    ) {}
    
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $token = $request->headers->get('Authorization');
        
        if (!$token || !str_starts_with($token, 'Bearer ')) {
            return null;
        }
        
        try {
            $payload = $this->jwtDecoder->decode(substr($token, 7));
            $tenantId = $payload['tenant_id'] ?? null;
            
            return $tenantId ? $this->tenantRegistry->getById($tenantId) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
```

```yaml
# Register custom resolver
services:
    App\Resolver\JwtTenantResolver:
        arguments:
            $tenantRegistry: '@zhortein_multi_tenant.tenant_registry'
            $jwtDecoder: '@app.jwt_decoder'

# Use in chain
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        order: [jwt, subdomain, header]
        strict: false
```

## Request Examples

### Example 5: Different Resolution Scenarios

```bash
# Scenario 1: Subdomain resolution
curl -H "Host: tenant1.myapp.com" https://myapp.com/api/current-tenant
# Resolves to tenant1 via subdomain

# Scenario 2: Header resolution (subdomain not found)
curl -H "X-Tenant-Id: tenant2" https://www.myapp.com/api/current-tenant
# Resolves to tenant2 via header

# Scenario 3: Query parameter resolution (fallback)
curl "https://myapp.com/api/current-tenant?tenant=tenant3"
# Resolves to tenant3 via query parameter

# Scenario 4: Ambiguous resolution (strict mode)
curl -H "X-Tenant-Id: tenant1" "https://myapp.com/api/current-tenant?tenant=tenant2"
# Returns 400 error with ambiguity details in dev mode
```

## Error Handling Examples

### Example 6: Development vs Production Responses

**Development Environment Response:**
```json
{
    "error": "Multiple tenant resolution strategies returned different results",
    "code": 400,
    "type": "ambiguous_resolution",
    "diagnostics": {
        "resolvers_tried": [
            {
                "name": "header",
                "result": "tenant1",
                "class": "Zhortein\\MultiTenantBundle\\Resolver\\HeaderTenantResolver"
            },
            {
                "name": "query",
                "result": "tenant2",
                "class": "Zhortein\\MultiTenantBundle\\Resolver\\QueryTenantResolver"
            }
        ],
        "resolvers_skipped": [],
        "strict_mode": true
    },
    "exception_message": "Ambiguous tenant resolution: resolvers header, query returned different tenants: tenant1, tenant2"
}
```

**Production Environment Response:**
```json
{
    "error": "Multiple tenant resolution strategies returned different results",
    "code": 400
}
```

### Example 7: Logging Configuration

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        tenant_resolution:
            type: stream
            path: '%kernel.logs_dir%/tenant_resolution.log'
            level: info
            channels: ['tenant_resolution']
```

**Log Output Examples:**
```
[2024-01-15 10:30:15] tenant_resolution.INFO: Tenant resolved by chain resolver {"resolver":"subdomain","tenant_slug":"tenant1","tenant_id":123,"position_in_chain":0}

[2024-01-15 10:30:16] tenant_resolution.WARNING: Resolver threw exception {"resolver":"header","exception":"Header not found","exception_class":"RuntimeException"}

[2024-01-15 10:30:17] tenant_resolution.DEBUG: Header resolver skipped due to allow-list {"resolver":"header","header_name":"X-Custom-Header","allow_list":["X-Tenant-Id"]}
```

## Testing Examples

### Example 8: Unit Testing Chain Behavior

```php
<?php

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;

class ChainResolverTest extends TestCase
{
    public function testPrecedenceOrder(): void
    {
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        
        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);
        
        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            false
        );
        
        $result = $chainResolver->resolveTenant(new Request());
        
        // Should return first resolver's result
        $this->assertSame($tenant1, $result);
    }
    
    public function testStrictModeAmbiguity(): void
    {
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        
        $resolver1 = $this->createMockResolver($tenant1);
        $resolver2 = $this->createMockResolver($tenant2);
        
        $chainResolver = new ChainTenantResolver(
            ['first' => $resolver1, 'second' => $resolver2],
            ['first', 'second'],
            true // strict mode
        );
        
        $this->expectException(AmbiguousTenantResolutionException::class);
        $chainResolver->resolveTenant(new Request());
    }
}
```

## Performance Considerations

### Example 9: Optimized Configuration

```yaml
# For high-traffic applications
zhortein_multi_tenant:
    resolver: chain
    resolver_chain:
        # Put fastest resolvers first
        order: [header, query, subdomain, path]
        strict: false  # Avoid consensus checking overhead
        header_allow_list: ["X-Tenant-Id"]  # Limit header processing
    
    # Cache tenant lookups
    tenant_registry:
        cache_enabled: true
        cache_ttl: 300  # 5 minutes
```

### Example 10: Monitoring and Metrics

```php
<?php

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Event\TenantResolvedEvent;

class TenantResolutionMetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MetricsCollectorInterface $metrics
    ) {}
    
    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $this->metrics->increment('tenant.resolution.success', [
            'resolver' => $event->getResolverName(),
            'tenant_id' => $event->getTenant()->getId(),
        ]);
    }
    
    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
        ];
    }
}
```

This comprehensive resolver chain system provides flexible, robust tenant resolution with excellent error handling, diagnostics, and performance characteristics suitable for production multi-tenant applications.