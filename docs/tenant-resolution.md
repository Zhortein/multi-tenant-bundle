# Tenant Resolution

Tenant resolution is the process of determining which tenant is associated with an incoming HTTP request. The bundle provides multiple resolution strategies and allows you to create custom resolvers for specific needs.

> 📖 **Navigation**: [← Doctrine Tenant Filter](doctrine-tenant-filter.md) | [Back to Documentation Index](index.md) | [Resolver Chain →](resolver-chain.md)

## Available Resolvers

### 1. Subdomain Resolver

Resolves tenants based on the subdomain of the request URL.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'subdomain'
        options:
            base_domain: 'example.com'
            excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp']
```

**Examples:**
- `acme.example.com` → tenant slug: `acme`
- `tech-startup.example.com` → tenant slug: `tech-startup`
- `www.example.com` → no tenant (excluded)
- `api.example.com` → no tenant (excluded)

**Implementation:**

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null; // This matches the subdomain

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null; // Optional: store full domain

    // ... other properties and methods
}
```

### 2. Path Resolver

Resolves tenants based on the first segment of the URL path.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'path'
        options:
            position: 1 # Position in path (1-based)
```

**Examples:**
- `/acme/dashboard` → tenant slug: `acme`
- `/tech-startup/products` → tenant slug: `tech-startup`
- `/admin/users` → tenant slug: `admin`

**Route Configuration:**

```yaml
# config/routes.yaml
tenant_routes:
    resource: '../src/Controller/'
    type: attribute
    prefix: '/{tenant}'
    requirements:
        tenant: '[a-z0-9\-]+'
```

**Controller Usage:**

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DashboardController extends AbstractController
{
    #[Route('/{tenant}/dashboard', name: 'tenant_dashboard')]
    public function index(
        string $tenant,
        TenantContextInterface $tenantContext
    ): Response {
        // The tenant is automatically resolved and available in context
        $currentTenant = $tenantContext->getTenant();
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $currentTenant,
        ]);
    }
}
```

### 3. Header Resolver

Resolves tenants based on HTTP headers.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'header'
        options:
            header_name: 'X-Tenant-ID'
```

**Examples:**
- Header: `X-Tenant-ID: acme` → tenant slug: `acme`
- Header: `X-Tenant-ID: tech-startup` → tenant slug: `tech-startup`

**API Usage:**

```bash
# cURL example
curl -H "X-Tenant-ID: acme" https://api.example.com/users

# JavaScript fetch example
fetch('/api/users', {
    headers: {
        'X-Tenant-ID': 'acme',
        'Content-Type': 'application/json'
    }
});
```

**API Controller:**

```php
<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[Route('/api', name: 'api_')]
class ApiController extends AbstractController
{
    #[Route('/users', name: 'users', methods: ['GET'])]
    public function getUsers(TenantContextInterface $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            return $this->json(['error' => 'Tenant required'], 400);
        }
        
        // Get tenant-specific users
        // ... implementation
        
        return $this->json([
            'tenant' => $tenant->getSlug(),
            'users' => [], // Your user data
        ]);
    }
}
```

### 4. DNS TXT Resolver

Resolves tenants based on DNS TXT records.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5          # DNS query timeout in seconds
        enable_cache: true  # Enable DNS result caching
```

**DNS Setup:**

The resolver queries DNS TXT records with the pattern `_tenant.<domain>`:

```bind
; BIND DNS configuration
_tenant.acme.com.     IN TXT "acme"
_tenant.bio.org.      IN TXT "bio"
_tenant.startup.io.   IN TXT "startup"
```

**Examples:**
- `https://acme.com/dashboard` → DNS query: `_tenant.acme.com` → tenant slug: `acme`
- `https://bio.org/products` → DNS query: `_tenant.bio.org` → tenant slug: `bio`
- `https://client1.saas.com/app` → DNS query: `_tenant.client1.saas.com` → tenant slug: `client1`

**Cloudflare DNS Setup:**

```bash
# Using Cloudflare API
curl -X POST "https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records" \
  -H "Authorization: Bearer {api_token}" \
  -H "Content-Type: application/json" \
  --data '{
    "type": "TXT",
    "name": "_tenant.acme",
    "content": "acme",
    "ttl": 300
  }'
```

**Service Usage:**

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsValidationService
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver
    ) {}

    public function validateDnsSetup(string $domain): array
    {
        return [
            'domain' => $domain,
            'dns_query' => $this->dnsResolver->getDnsQueryForHost($domain),
            'has_record' => $this->dnsResolver->hasDnsTxtRecord($domain),
            'tenant_id' => $this->dnsResolver->getTenantIdentifierFromDns($domain),
        ];
    }
}
```

**Use Cases:**
- Multi-domain setups with DNS control
- Dynamic tenant assignment without code changes
- Distributed systems with DNS-based configuration
- White-label solutions with custom domains

**Advantages:**
- Decentralized configuration (stored in DNS)
- Dynamic updates without application restarts
- Excellent caching performance
- Works across different domains

**Considerations:**
- Requires DNS control and management
- DNS propagation delays (up to 48 hours)
- Additional DNS query overhead
- Dependency on DNS infrastructure reliability

For detailed DNS setup instructions and advanced configuration, see the [DNS TXT Resolver Documentation](dns-txt-resolver.md).

### 5. Domain-Based Resolver

Resolves tenants based on exact domain mapping.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            tenant-one.com: tenant_one
            acme.org: acme
            bio-corp.net: bio
```

**Examples:**
- `https://tenant-one.com/dashboard` → tenant slug: `tenant_one`
- `https://acme.org/products` → tenant slug: `acme`
- `https://unknown-domain.com/page` → no tenant (not mapped)

For detailed configuration and examples, see the [Domain Resolvers Documentation](domain-resolvers.md).

### 6. Hybrid Domain-Subdomain Resolver

Combines domain mapping with subdomain pattern matching.

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            acme-client.com: acme
            bio-portal.org: bio
        subdomain_mapping:
            '*.myplatform.com': use_subdomain_as_slug
            '*.shared-platform.net': shared_tenant
        excluded_subdomains:
            - www
            - api
            - admin
```

**Examples:**
- `https://acme-client.com/app` → tenant slug: `acme` (domain mapping)
- `https://tenant1.myplatform.com/dashboard` → tenant slug: `tenant1` (subdomain pattern)
- `https://anything.shared-platform.net/page` → tenant slug: `shared_tenant` (fixed strategy)
- `https://www.myplatform.com/home` → no tenant (excluded subdomain)

For detailed configuration and examples, see the [Domain Resolvers Documentation](domain-resolvers.md).

### 7. Custom Resolver

Create custom resolvers for specific business logic.

**Custom Resolver Implementation:**

```php
<?php

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class DatabaseTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        // Example: Resolve tenant based on user authentication
        $user = $this->getAuthenticatedUser($request);
        
        if (!$user) {
            return null;
        }
        
        // Get tenant from user's organization
        $organizationId = $user->getOrganizationId();
        
        try {
            return $this->tenantRegistry->getBySlug($organizationId);
        } catch (\Exception) {
            return null;
        }
    }
    
    private function getAuthenticatedUser(Request $request): ?User
    {
        // Your authentication logic here
        // This is just an example
        $token = $request->headers->get('Authorization');
        
        if (!$token) {
            return null;
        }
        
        // Decode JWT token, validate session, etc.
        return $this->userRepository->findByToken($token);
    }
}
```

**Service Registration:**

```yaml
# config/services.yaml
services:
    App\Resolver\DatabaseTenantResolver:
        tags:
            - { name: 'zhortein.tenant_resolver', priority: 10 }
```

**Configuration:**

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'custom'
        service: 'App\Resolver\DatabaseTenantResolver'
```

## Advanced Custom Resolvers

### Multi-Strategy Resolver

```php
<?php

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class MultiStrategyTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        // Strategy 1: Try subdomain first
        $tenant = $this->resolveBySubdomain($request);
        if ($tenant) {
            return $tenant;
        }
        
        // Strategy 2: Try header
        $tenant = $this->resolveByHeader($request);
        if ($tenant) {
            return $tenant;
        }
        
        // Strategy 3: Try path
        return $this->resolveByPath($request);
    }
    
    private function resolveBySubdomain(Request $request): ?TenantInterface
    {
        $host = $request->getHost();
        $parts = explode('.', $host);
        
        if (count($parts) < 3) {
            return null; // No subdomain
        }
        
        $subdomain = $parts[0];
        
        try {
            return $this->tenantRegistry->getBySlug($subdomain);
        } catch (\Exception) {
            return null;
        }
    }
    
    private function resolveByHeader(Request $request): ?TenantInterface
    {
        $tenantId = $request->headers->get('X-Tenant-ID');
        
        if (!$tenantId) {
            return null;
        }
        
        try {
            return $this->tenantRegistry->getBySlug($tenantId);
        } catch (\Exception) {
            return null;
        }
    }
    
    private function resolveByPath(Request $request): ?TenantInterface
    {
        $pathInfo = trim($request->getPathInfo(), '/');
        $segments = explode('/', $pathInfo);
        
        if (empty($segments[0])) {
            return null;
        }
        
        try {
            return $this->tenantRegistry->getBySlug($segments[0]);
        } catch (\Exception) {
            return null;
        }
    }
}
```

### Domain Mapping Resolver

```php
<?php

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class DomainMappingTenantResolver implements TenantResolverInterface
{
    private array $domainMapping = [
        'acme.com' => 'acme',
        'techstartup.io' => 'tech-startup',
        'retailstore.net' => 'retail-store',
    ];

    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $host = $request->getHost();
        
        // Check exact domain mapping
        if (isset($this->domainMapping[$host])) {
            $tenantSlug = $this->domainMapping[$host];
            
            try {
                return $this->tenantRegistry->getBySlug($tenantSlug);
            } catch (\Exception) {
                return null;
            }
        }
        
        // Check if tenant has this domain configured
        foreach ($this->tenantRegistry->getAll() as $tenant) {
            if ($tenant->getDomain() === $host) {
                return $tenant;
            }
        }
        
        return null;
    }
}
```

## Resolver Priority

When multiple resolvers are registered, they are executed in priority order:

```yaml
# config/services.yaml
services:
    App\Resolver\PrimaryResolver:
        tags:
            - { name: 'zhortein.tenant_resolver', priority: 100 }
    
    App\Resolver\FallbackResolver:
        tags:
            - { name: 'zhortein.tenant_resolver', priority: 10 }
```

Higher priority resolvers are executed first. The first resolver that returns a tenant wins.

## Configuration Options

### Global Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Tenant entity class
    tenant_entity: 'App\Entity\Tenant'
    
    # Resolver configuration
    resolver:
        type: 'subdomain' # subdomain, path, header, or custom
        options:
            # Subdomain resolver options
            base_domain: 'example.com'
            excluded_subdomains: ['www', 'api', 'admin']
            
            # Path resolver options
            position: 1
            
            # Header resolver options
            header_name: 'X-Tenant-ID'
            
            # Custom resolver options
            service: 'App\Resolver\CustomResolver'
    
    # Default tenant (optional)
    default_tenant: null
    
    # Require tenant for all requests
    require_tenant: false
```

### Environment-Specific Configuration

```yaml
# config/packages/dev/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'path' # Use path resolver in development
        options:
            position: 1

# config/packages/prod/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver:
        type: 'subdomain' # Use subdomain resolver in production
        options:
            base_domain: '%env(APP_DOMAIN)%'
```

## Testing Resolvers

### Unit Testing

```php
<?php

namespace App\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use App\Resolver\CustomTenantResolver;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

class CustomTenantResolverTest extends TestCase
{
    public function testResolveBySubdomain(): void
    {
        $tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $resolver = new CustomTenantResolver($tenantRegistry);
        
        $request = Request::create('https://acme.example.com/dashboard');
        
        $tenantRegistry
            ->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($this->createMockTenant('acme'));
        
        $tenant = $resolver->resolve($request);
        
        $this->assertNotNull($tenant);
        $this->assertEquals('acme', $tenant->getSlug());
    }
    
    private function createMockTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        return $tenant;
    }
}
```

### Functional Testing

```php
<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TenantResolutionTest extends WebTestCase
{
    public function testSubdomainResolution(): void
    {
        $client = static::createClient();
        
        // Test subdomain resolution
        $client->request('GET', '/dashboard', [], [], [
            'HTTP_HOST' => 'acme.example.com'
        ]);
        
        $this->assertResponseIsSuccessful();
        
        // Verify tenant context is set correctly
        $tenantContext = static::getContainer()->get('tenant.context');
        $tenant = $tenantContext->getTenant();
        
        $this->assertNotNull($tenant);
        $this->assertEquals('acme', $tenant->getSlug());
    }
    
    public function testPathResolution(): void
    {
        $client = static::createClient();
        
        // Test path resolution
        $client->request('GET', '/acme/dashboard');
        
        $this->assertResponseIsSuccessful();
        
        // Verify tenant context
        $tenantContext = static::getContainer()->get('tenant.context');
        $tenant = $tenantContext->getTenant();
        
        $this->assertNotNull($tenant);
        $this->assertEquals('acme', $tenant->getSlug());
    }
}
```

## Error Handling

### Tenant Not Found

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private bool $requireTenant = false,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -20], // After tenant resolution
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // Skip validation for certain routes
        if ($this->shouldSkipValidation($request)) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();

        if ($this->requireTenant && !$tenant) {
            $response = new JsonResponse([
                'error' => 'Tenant required',
                'message' => 'No valid tenant found for this request'
            ], Response::HTTP_BAD_REQUEST);
            
            $event->setResponse($response);
        }
    }
    
    private function shouldSkipValidation(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        
        // Skip validation for health checks, admin routes, etc.
        $skipRoutes = ['health_check', 'admin_', 'api_doc'];
        
        foreach ($skipRoutes as $skipRoute) {
            if (str_starts_with($route, $skipRoute)) {
                return true;
            }
        }
        
        return false;
    }
}
```

## Best Practices

### 1. Choose the Right Strategy

- **Subdomain**: Best for SaaS applications with custom domains
- **Path**: Good for development and simple multi-tenancy
- **Header**: Ideal for API-first applications
- **Custom**: When you need complex business logic

### 2. Handle Edge Cases

```php
public function resolve(Request $request): ?TenantInterface
{
    // Always validate input
    $tenantSlug = $this->extractTenantSlug($request);
    
    if (!$tenantSlug || !$this->isValidSlug($tenantSlug)) {
        return null;
    }
    
    try {
        $tenant = $this->tenantRegistry->getBySlug($tenantSlug);
        
        // Validate tenant is active
        if (!$tenant->isActive()) {
            return null;
        }
        
        return $tenant;
    } catch (\Exception) {
        return null;
    }
}

private function isValidSlug(string $slug): bool
{
    return preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
}
```

### 3. Cache Tenant Resolution

```php
<?php

namespace App\Resolver;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class CachedTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private CacheItemPoolInterface $cache,
        private int $cacheTtl = 3600,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $cacheKey = $this->getCacheKey($request);
        $cacheItem = $this->cache->getItem($cacheKey);
        
        if ($cacheItem->isHit()) {
            $tenantSlug = $cacheItem->get();
            
            if ($tenantSlug === null) {
                return null;
            }
            
            try {
                return $this->tenantRegistry->getBySlug($tenantSlug);
            } catch (\Exception) {
                // Cache is stale, continue with fresh resolution
            }
        }
        
        // Resolve tenant
        $tenant = $this->doResolve($request);
        
        // Cache result
        $cacheItem->set($tenant?->getSlug());
        $cacheItem->expiresAfter($this->cacheTtl);
        $this->cache->save($cacheItem);
        
        return $tenant;
    }
    
    private function getCacheKey(Request $request): string
    {
        return 'tenant_resolution_' . md5($request->getHost() . $request->getPathInfo());
    }
    
    private function doResolve(Request $request): ?TenantInterface
    {
        // Your actual resolution logic here
        return null;
    }
}
```

### 4. Monitor Resolution Performance

```php
<?php

namespace App\Resolver;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Stopwatch\Stopwatch;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class MonitoredTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantResolverInterface $innerResolver,
        private LoggerInterface $logger,
        private Stopwatch $stopwatch,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $this->stopwatch->start('tenant_resolution');
        
        try {
            $tenant = $this->innerResolver->resolve($request);
            
            $event = $this->stopwatch->stop('tenant_resolution');
            
            $this->logger->info('Tenant resolution completed', [
                'duration_ms' => $event->getDuration(),
                'memory_mb' => $event->getMemory() / 1024 / 1024,
                'tenant_slug' => $tenant?->getSlug(),
                'request_host' => $request->getHost(),
                'request_path' => $request->getPathInfo(),
            ]);
            
            return $tenant;
        } catch (\Exception $e) {
            $this->stopwatch->stop('tenant_resolution');
            
            $this->logger->error('Tenant resolution failed', [
                'error' => $e->getMessage(),
                'request_host' => $request->getHost(),
                'request_path' => $request->getPathInfo(),
            ]);
            
            throw $e;
        }
    }
}
```

## Troubleshooting

### Common Issues

1. **Tenant Not Resolved**: Check resolver configuration and tenant data
2. **Wrong Tenant Resolved**: Verify resolver priority and logic
3. **Performance Issues**: Consider caching and monitoring
4. **Case Sensitivity**: Ensure consistent slug formatting

### Debug Commands

```bash
# Test tenant resolution
php bin/console debug:container tenant.resolver

# List all tenants
php bin/console tenant:list

# Test specific tenant
php bin/console tenant:show acme
```

---

> 📖 **Navigation**: [← Doctrine Tenant Filter](doctrine-tenant-filter.md) | [Back to Documentation Index](index.md) | [Resolver Chain →](resolver-chain.md)