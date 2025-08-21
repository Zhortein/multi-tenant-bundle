# Domain Resolver Usage Examples

> 📖 **Navigation**: [← Resolver Chain Usage](resolver-chain-usage.md) | [Back to Documentation Index](../index.md) | [DNS TXT Resolver Usage →](dns-txt-resolver-usage.md)

This document provides practical examples of using the domain and hybrid domain resolvers in the Zhortein Multi-Tenant Bundle.

## Domain Resolver

The domain resolver maps full domain names to tenant slugs, perfect for white-label applications where each tenant has their own domain.

### Basic Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            'acme-corp.com': 'acme'
            'beta-client.com': 'beta'
            'demo-company.org': 'demo'
```

### Example 1: Simple Domain Mapping

```yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            'client1.com': 'client_one'
            'client2.com': 'client_two'
            'mycorp.net': 'corporate'
            'startup.io': 'startup_tenant'
```

**How it works:**
- `https://client1.com/dashboard` → tenant slug: `client_one`
- `https://client2.com/api/users` → tenant slug: `client_two`
- `https://mycorp.net/products` → tenant slug: `corporate`
- `https://unknown.com/page` → no tenant (not in mapping)

### Example 2: Using Domain Resolver in Controller

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DomainController extends AbstractController
{
    #[Route('/api/domain-info', name: 'domain_info')]
    public function getDomainInfo(Request $request, TenantContextInterface $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            return new JsonResponse([
                'error' => 'No tenant found for domain',
                'domain' => $request->getHost(),
            ], 404);
        }
        
        return new JsonResponse([
            'tenant_id' => $tenant->getId(),
            'tenant_slug' => $tenant->getSlug(),
            'tenant_name' => $tenant->getName(),
            'domain' => $request->getHost(),
            'resolution_method' => 'domain_mapping',
        ]);
    }
}
```

## Hybrid Domain Resolver

The hybrid resolver combines domain mapping with subdomain resolution, providing maximum flexibility.

### Basic Configuration

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            'acme-client.com': 'acme'
            'beta-corp.com': 'beta'
        subdomain_mapping:
            '*.myplatform.com': 'use_subdomain_as_slug'
            '*.saas-app.net': 'use_subdomain_as_slug'
        excluded_subdomains: ['www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static']
```

### Example 3: Comprehensive Hybrid Configuration

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        # Direct domain mappings (highest priority)
        domain_mapping:
            'enterprise-client.com': 'enterprise'
            'premium-customer.org': 'premium'
            'legacy-system.net': 'legacy'
        
        # Subdomain patterns (lower priority)
        subdomain_mapping:
            '*.myapp.com': 'use_subdomain_as_slug'
            '*.platform.io': 'use_subdomain_as_slug'
            '*.dev.myapp.com': 'use_subdomain_as_slug'
        
        # Excluded subdomains (no tenant resolution)
        excluded_subdomains: 
            - 'www'
            - 'api'
            - 'admin'
            - 'mail'
            - 'ftp'
            - 'cdn'
            - 'static'
            - 'assets'
```

**Resolution Examples:**
- `https://enterprise-client.com/page` → tenant: `enterprise` (domain mapping)
- `https://tenant1.myapp.com/dashboard` → tenant: `tenant1` (subdomain)
- `https://demo.platform.io/api` → tenant: `demo` (subdomain)
- `https://www.myapp.com/page` → no tenant (excluded subdomain)

### Example 4: Custom Hybrid Resolver

```php
<?php

namespace App\Resolver;

use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Symfony\Component\HttpFoundation\Request;

class CustomHybridResolver extends HybridDomainSubdomainResolver
{
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();
        $this->logger?->info('Attempting hybrid resolution', ['host' => $host]);
        
        // Try parent resolution first
        $tenant = parent::resolveTenant($request);
        
        if ($tenant) {
            $this->logger?->info('Hybrid resolution successful', [
                'host' => $host,
                'tenant_slug' => $tenant->getSlug(),
                'resolution_type' => $this->getLastResolutionType(),
            ]);
        } else {
            $this->logger?->debug('Hybrid resolution failed', ['host' => $host]);
        }
        
        return $tenant;
    }
    
    private function getLastResolutionType(): string
    {
        // Custom logic to track how the tenant was resolved
        // This would need to be implemented based on your needs
        return 'unknown';
    }
}
```

### Example 5: Dynamic Domain Mapping

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DynamicDomainMappingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRegistryInterface $tenantRegistry
    ) {}
    
    public function addDomainMapping(string $domain, string $tenantSlug): void
    {
        // Store domain mapping in database
        $mapping = new DomainMapping();
        $mapping->setDomain($domain);
        $mapping->setTenantSlug($tenantSlug);
        
        $this->entityManager->persist($mapping);
        $this->entityManager->flush();
        
        // Clear any caches
        $this->clearDomainMappingCache();
    }
    
    public function removeDomainMapping(string $domain): void
    {
        $mapping = $this->entityManager->getRepository(DomainMapping::class)
            ->findOneBy(['domain' => $domain]);
            
        if ($mapping) {
            $this->entityManager->remove($mapping);
            $this->entityManager->flush();
            $this->clearDomainMappingCache();
        }
    }
    
    public function getDomainMappings(): array
    {
        return $this->entityManager->getRepository(DomainMapping::class)
            ->findAll();
    }
    
    private function clearDomainMappingCache(): void
    {
        // Clear relevant caches
        // Implementation depends on your caching strategy
    }
}
```

## Testing Examples

### Example 6: Unit Testing Domain Resolution

```php
<?php

namespace App\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Symfony\Component\HttpFoundation\Request;

class DomainResolverTest extends TestCase
{
    public function testDomainMapping(): void
    {
        $domainMapping = [
            'client1.com' => 'client_one',
            'client2.com' => 'client_two',
        ];
        
        $mockTenant = $this->createMockTenant('client_one');
        $mockRegistry = $this->createMock(TenantRegistryInterface::class);
        $mockRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('client_one')
            ->willReturn($mockTenant);
        
        $resolver = new DomainBasedTenantResolver($mockRegistry, $domainMapping);
        
        $request = Request::create('https://client1.com/page');
        $tenant = $resolver->resolveTenant($request);
        
        $this->assertNotNull($tenant);
        $this->assertEquals('client_one', $tenant->getSlug());
    }
    
    public function testUnmappedDomain(): void
    {
        $domainMapping = ['client1.com' => 'client_one'];
        $mockRegistry = $this->createMock(TenantRegistryInterface::class);
        
        $resolver = new DomainBasedTenantResolver($mockRegistry, $domainMapping);
        
        $request = Request::create('https://unknown.com/page');
        $tenant = $resolver->resolveTenant($request);
        
        $this->assertNull($tenant);
    }
    
    private function createMockTenant(string $slug): object
    {
        $tenant = $this->createMock(\Zhortein\MultiTenantBundle\Entity\TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getId')->willReturn(123);
        return $tenant;
    }
}
```

### Example 7: Integration Testing

```php
<?php

namespace App\Tests\Integration;

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class DomainResolverIntegrationTest extends TenantWebTestCase
{
    public function testDomainResolution(): void
    {
        // Create test tenant
        $tenant = $this->createTestTenant('domain-test-tenant');
        
        // Configure domain mapping for test
        $this->configureDomainMapping([
            'test-domain.local' => 'domain-test-tenant'
        ]);
        
        $client = static::createClient();
        $client->request('GET', 'https://test-domain.local/api/tenant');
        
        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('domain-test-tenant', $data['tenant_slug']);
    }
    
    public function testHybridResolution(): void
    {
        // Test both domain mapping and subdomain resolution
        $tenant1 = $this->createTestTenant('mapped-tenant');
        $tenant2 = $this->createTestTenant('subdomain-tenant');
        
        $this->configureHybridMapping([
            'domain_mapping' => ['mapped.local' => 'mapped-tenant'],
            'subdomain_mapping' => ['*.test.local' => 'use_subdomain_as_slug'],
        ]);
        
        $client = static::createClient();
        
        // Test domain mapping
        $client->request('GET', 'https://mapped.local/api/tenant');
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('mapped-tenant', $data['tenant_slug']);
        
        // Test subdomain resolution
        $client->request('GET', 'https://subdomain-tenant.test.local/api/tenant');
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('subdomain-tenant', $data['tenant_slug']);
    }
}
```

## Advanced Use Cases

### Example 8: Multi-Environment Domain Mapping

```yaml
# config/packages/dev/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            'client1.local': 'client_one'
            'client2.local': 'client_two'
        subdomain_mapping:
            '*.dev.local': 'use_subdomain_as_slug'

# config/packages/prod/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            'client1.com': 'client_one'
            'client2.com': 'client_two'
            'enterprise.net': 'enterprise'
        subdomain_mapping:
            '*.myapp.com': 'use_subdomain_as_slug'
```

### Example 9: Domain Validation Service

```php
<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class DomainValidationService
{
    public function __construct(
        private array $allowedDomains = [],
        private array $blockedDomains = []
    ) {}
    
    public function isValidTenantDomain(string $domain): bool
    {
        // Check if domain is blocked
        if (in_array($domain, $this->blockedDomains, true)) {
            return false;
        }
        
        // If allow list is configured, check it
        if (!empty($this->allowedDomains)) {
            return in_array($domain, $this->allowedDomains, true);
        }
        
        // Additional validation logic
        return $this->validateDomainFormat($domain);
    }
    
    private function validateDomainFormat(string $domain): bool
    {
        // Basic domain format validation
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
    
    public function extractTenantFromDomain(string $domain): ?string
    {
        // Custom logic to extract tenant identifier from domain
        // This could involve database lookups, pattern matching, etc.
        
        if (preg_match('/^([a-z0-9-]+)\.myapp\.com$/', $domain, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
}
```

### Example 10: Performance Monitoring

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Event\TenantResolvedEvent;
use Psr\Log\LoggerInterface;

class DomainResolutionMonitoringSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private array $performanceThresholds = []
    ) {}
    
    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $request = $event->getRequest();
        $tenant = $event->getTenant();
        $resolver = $event->getResolverName();
        
        // Log domain resolution
        $this->logger->info('Domain resolution completed', [
            'resolver' => $resolver,
            'domain' => $request->getHost(),
            'tenant_slug' => $tenant->getSlug(),
            'user_agent' => $request->headers->get('User-Agent'),
            'ip' => $request->getClientIp(),
        ]);
        
        // Check for suspicious patterns
        $this->checkForSuspiciousActivity($request, $tenant);
    }
    
    private function checkForSuspiciousActivity(Request $request, $tenant): void
    {
        $domain = $request->getHost();
        
        // Example: Check for domain spoofing attempts
        if ($this->isPotentialSpoofing($domain)) {
            $this->logger->warning('Potential domain spoofing detected', [
                'domain' => $domain,
                'tenant_slug' => $tenant->getSlug(),
                'ip' => $request->getClientIp(),
            ]);
        }
    }
    
    private function isPotentialSpoofing(string $domain): bool
    {
        // Implement spoofing detection logic
        // This could check for similar-looking domains, etc.
        return false;
    }
    
    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
        ];
    }
}
```

## Production Considerations

### SSL Certificate Management

```bash
#!/bin/bash
# Script to manage SSL certificates for tenant domains

DOMAIN="$1"
ACTION="$2"

case $ACTION in
    "add")
        # Add SSL certificate for new tenant domain
        certbot certonly --webroot \
            -w /var/www/html \
            -d "$DOMAIN" \
            --email admin@myapp.com \
            --agree-tos \
            --non-interactive
        
        # Update nginx configuration
        nginx -s reload
        ;;
    "remove")
        # Remove SSL certificate
        certbot delete --cert-name "$DOMAIN" --non-interactive
        ;;
esac
```

### DNS Management

```php
<?php

namespace App\Service;

class DnsManagementService
{
    public function addDomainRecord(string $domain, string $ip): void
    {
        // Add A record for tenant domain
        // Implementation depends on your DNS provider (Route53, Cloudflare, etc.)
    }
    
    public function removeDomainRecord(string $domain): void
    {
        // Remove A record for tenant domain
    }
    
    public function validateDomainOwnership(string $domain): bool
    {
        // Validate that the tenant owns the domain
        // Could use DNS TXT record verification
        return true;
    }
}
```

Domain and hybrid resolvers provide powerful, flexible tenant resolution for applications where tenants have their own domains or need sophisticated routing logic combining multiple resolution strategies.

---

> 📖 **Navigation**: [← Back to Examples](../examples/) | [DNS TXT Resolver Usage →](dns-txt-resolver-usage.md)