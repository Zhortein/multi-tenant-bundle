# DNS TXT Resolver Usage Examples

> 📖 **Navigation**: [← Domain Resolver Usage](domain-resolver-usage.md) | [Back to Documentation Index](../index.md) | [Mailer Usage →](mailer-usage.md)

This document provides practical examples of using the DNS TXT resolver in the Zhortein Multi-Tenant Bundle.

## Basic Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5  # DNS query timeout in seconds
        enable_cache: true  # Enable DNS result caching
```

## DNS Record Setup

### Example 1: Basic TXT Record

```bash
# DNS TXT record for example.com
_tenant.example.com. IN TXT "tenant-slug=acme"
```

**How it works:**
- Request to `https://example.com/dashboard`
- Bundle queries `_tenant.example.com` TXT record
- Finds `tenant-slug=acme`
- Resolves to tenant with slug `acme`

### Example 2: Multiple Domains

```bash
# For client1.com
_tenant.client1.com. IN TXT "tenant-slug=client1"

# For client2.com  
_tenant.client2.com. IN TXT "tenant-slug=client2"

# For subdomain
_tenant.app.client1.com. IN TXT "tenant-slug=client1-app"
```

### Example 3: Complex TXT Records

```bash
# Multiple tenant information in one record
_tenant.example.com. IN TXT "tenant-slug=acme;tenant-id=123;environment=production"

# Hierarchical tenant structure
_tenant.app.acme.com. IN TXT "tenant-slug=acme-app;parent=acme"

# Geographic distribution
_tenant.us.example.com. IN TXT "tenant-slug=acme-us;region=us-east-1"
_tenant.eu.example.com. IN TXT "tenant-slug=acme-eu;region=eu-west-1"
```

## Code Examples

### Example 4: Using DNS TXT Resolver in Controller

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class TenantController extends AbstractController
{
    #[Route('/api/tenant/dns-info', name: 'tenant_dns_info')]
    public function getDnsInfo(Request $request, DnsTxtTenantResolver $dnsResolver): JsonResponse
    {
        try {
            $tenant = $dnsResolver->resolveTenant($request);
            
            if (!$tenant) {
                return new JsonResponse(['error' => 'No tenant found via DNS'], 404);
            }
            
            return new JsonResponse([
                'tenant_id' => $tenant->getId(),
                'tenant_slug' => $tenant->getSlug(),
                'resolution_method' => 'dns_txt',
                'domain' => $request->getHost(),
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'DNS resolution failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
```

### Example 5: Custom DNS TXT Resolver

```php
<?php

namespace App\Resolver;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Symfony\Component\HttpFoundation\Request;

class CustomDnsTxtResolver extends DnsTxtTenantResolver
{
    protected function buildDnsQuery(string $domain): string
    {
        // Custom DNS query format - use different prefix
        return "_app-tenant.{$domain}";
    }
    
    protected function parseTxtRecord(string $record): ?string
    {
        // Support multiple formats
        if (preg_match('/tenant-id=([a-zA-Z0-9-_]+)/', $record, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/app-slug=([a-zA-Z0-9-_]+)/', $record, $matches)) {
            return $matches[1];
        }
        
        return parent::parseTxtRecord($record);
    }
    
    public function resolveTenant(Request $request): ?TenantInterface
    {
        // Add custom logging
        $domain = $request->getHost();
        $this->logger?->info('Attempting DNS TXT resolution', ['domain' => $domain]);
        
        $result = parent::resolveTenant($request);
        
        if ($result) {
            $this->logger?->info('DNS TXT resolution successful', [
                'domain' => $domain,
                'tenant_slug' => $result->getSlug(),
            ]);
        } else {
            $this->logger?->debug('DNS TXT resolution failed', ['domain' => $domain]);
        }
        
        return $result;
    }
}
```

### Example 6: DNS with Fallback Strategy

```php
<?php

namespace App\Resolver;

use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\SubdomainTenantResolver;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

class DnsWithFallbackResolver implements TenantResolverInterface
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver,
        private SubdomainTenantResolver $subdomainResolver,
        private ?LoggerInterface $logger = null
    ) {}
    
    public function resolveTenant(Request $request): ?TenantInterface
    {
        // Try DNS first
        try {
            $tenant = $this->dnsResolver->resolveTenant($request);
            if ($tenant) {
                $this->logger?->info('Tenant resolved via DNS TXT', [
                    'tenant_slug' => $tenant->getSlug(),
                    'domain' => $request->getHost(),
                ]);
                return $tenant;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('DNS TXT resolution failed, trying fallback', [
                'domain' => $request->getHost(),
                'error' => $e->getMessage(),
            ]);
        }
        
        // Fallback to subdomain
        $tenant = $this->subdomainResolver->resolveTenant($request);
        if ($tenant) {
            $this->logger?->info('Tenant resolved via subdomain fallback', [
                'tenant_slug' => $tenant->getSlug(),
                'domain' => $request->getHost(),
            ]);
        }
        
        return $tenant;
    }
}
```

## Testing Examples

### Example 7: Unit Testing DNS Resolution

```php
<?php

namespace App\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Symfony\Component\HttpFoundation\Request;

class DnsTxtResolverTest extends TestCase
{
    public function testDnsResolution(): void
    {
        $mockTenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $mockTenant = $this->createMockTenant('test-tenant');
        
        $mockTenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($mockTenant);
            
        // Mock DNS resolver would need to be injected
        $resolver = new DnsTxtTenantResolver($mockTenantRegistry, 5, true);
        
        $request = Request::create('https://example.com/page');
        
        // In real test, you'd mock the DNS query
        // This is a simplified example
        $this->markTestSkipped('Requires DNS mocking setup');
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

### Example 8: Integration Testing with Test Kit

```php
<?php

namespace App\Tests\Integration;

use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

class DnsTxtIntegrationTest extends TenantWebTestCase
{
    public function testDnsTxtResolution(): void
    {
        // Create test tenant
        $tenant = $this->createTestTenant('dns-test-tenant');
        
        // Mock DNS response (implementation depends on your DNS mocking strategy)
        $this->mockDnsResponse('_tenant.test.local', 'tenant-slug=dns-test-tenant');
        
        $client = static::createClient();
        $client->request('GET', 'https://test.local/api/tenant');
        
        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('dns-test-tenant', $data['tenant_slug']);
    }
    
    private function mockDnsResponse(string $query, string $response): void
    {
        // Implementation depends on your DNS mocking strategy
        // This could use a test DNS server or mock the DNS resolver service
    }
}
```

## Performance Optimization

### Example 9: Caching Configuration

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            dns_tenant_cache:
                adapter: cache.adapter.redis
                default_lifetime: 300  # 5 minutes
                
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
    cache:
        pool: 'dns_tenant_cache'
        ttl: 300
```

### Example 10: Monitoring DNS Performance

```php
<?php

namespace App\Resolver;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class MonitoredDnsTxtResolver extends DnsTxtTenantResolver
{
    public function __construct(
        private Stopwatch $stopwatch,
        // ... other dependencies from parent
    ) {
        parent::__construct(/* ... parent constructor args ... */);
    }
    
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $domain = $request->getHost();
        $this->stopwatch->start('dns_resolution');
        
        try {
            $result = parent::resolveTenant($request);
            
            $event = $this->stopwatch->stop('dns_resolution');
            $this->logger?->info('DNS resolution completed', [
                'duration_ms' => $event->getDuration(),
                'memory_bytes' => $event->getMemory(),
                'domain' => $domain,
                'success' => $result !== null,
                'tenant_slug' => $result?->getSlug(),
            ]);
            
            return $result;
        } catch (\Exception $e) {
            $event = $this->stopwatch->stop('dns_resolution');
            $this->logger?->error('DNS resolution failed', [
                'duration_ms' => $event->getDuration(),
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

## Error Handling

### Example 11: Graceful DNS Failures

```php
<?php

namespace App\Resolver;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Symfony\Component\HttpFoundation\Request;

class RobustDnsTxtResolver extends DnsTxtTenantResolver
{
    public function resolveTenant(Request $request): ?TenantInterface
    {
        try {
            return parent::resolveTenant($request);
        } catch (\Exception $e) {
            $domain = $request->getHost();
            
            // Log different types of DNS failures
            if (str_contains($e->getMessage(), 'timeout')) {
                $this->logger?->warning('DNS timeout, using fallback', [
                    'domain' => $domain,
                    'timeout' => $this->getTimeout(),
                ]);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->logger?->debug('No DNS TXT record found', [
                    'domain' => $domain,
                ]);
            } else {
                $this->logger?->error('DNS resolution error', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
            }
            
            return $this->getFallbackTenant($request);
        }
    }
    
    private function getFallbackTenant(Request $request): ?TenantInterface
    {
        // Implement fallback logic based on your needs
        // Could return a default tenant, null, or try another resolution method
        return null;
    }
    
    private function getTimeout(): int
    {
        // Return configured timeout value
        return 5; // or get from configuration
    }
}
```

## Production Considerations

### Example 12: DNS TXT Record Management

```bash
#!/bin/bash
# Script to manage DNS TXT records for tenants

DOMAIN="$1"
TENANT_SLUG="$2"
ACTION="$3"

case $ACTION in
    "add")
        # Add DNS TXT record
        aws route53 change-resource-record-sets \
            --hosted-zone-id Z123456789 \
            --change-batch '{
                "Changes": [{
                    "Action": "CREATE",
                    "ResourceRecordSet": {
                        "Name": "_tenant.'$DOMAIN'",
                        "Type": "TXT",
                        "TTL": 300,
                        "ResourceRecords": [{"Value": "\"tenant-slug='$TENANT_SLUG'\""}]
                    }
                }]
            }'
        ;;
    "remove")
        # Remove DNS TXT record
        aws route53 change-resource-record-sets \
            --hosted-zone-id Z123456789 \
            --change-batch '{
                "Changes": [{
                    "Action": "DELETE",
                    "ResourceRecordSet": {
                        "Name": "_tenant.'$DOMAIN'",
                        "Type": "TXT",
                        "TTL": 300,
                        "ResourceRecords": [{"Value": "\"tenant-slug='$TENANT_SLUG'\""}]
                    }
                }]
            }'
        ;;
esac
```

The DNS TXT resolver provides a flexible, external way to resolve tenants using DNS records, particularly useful for scenarios where you want to avoid hardcoding tenant mappings in your application configuration and need dynamic tenant-to-domain associations.

---

> 📖 **Navigation**: [← Back to Examples](../examples/) | [Resolver Chain Usage →](resolver-chain-usage.md)