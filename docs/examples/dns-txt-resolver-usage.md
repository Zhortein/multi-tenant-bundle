# DNS TXT Resolver Usage Examples

This document provides practical examples of using the DNS TXT Resolver in real-world scenarios.

## Basic Setup

### 1. Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
```

### 2. DNS Records Setup

#### BIND DNS Configuration

```bind
; Zone file for example.com
$TTL 300

; Main tenant domains
_tenant.acme.com.           IN TXT "acme"
_tenant.bio-corp.org.       IN TXT "bio"
_tenant.startup-inc.io.     IN TXT "startup"

; Subdomain tenants
_tenant.client1.saas.com.   IN TXT "client1"
_tenant.client2.saas.com.   IN TXT "client2"
_tenant.demo.platform.com.  IN TXT "demo"

; Environment-specific
_tenant.dev.acme.com.       IN TXT "acme_dev"
_tenant.staging.acme.com.   IN TXT "acme_staging"
```

#### Cloudflare DNS Setup

```bash
# Production domains
curl -X POST "https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records" \
  -H "Authorization: Bearer {api_token}" \
  -H "Content-Type: application/json" \
  --data '{
    "type": "TXT",
    "name": "_tenant.acme",
    "content": "acme",
    "ttl": 300
  }'

# Development domains
curl -X POST "https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records" \
  -H "Authorization: Bearer {api_token}" \
  -H "Content-Type: application/json" \
  --data '{
    "type": "TXT",
    "name": "_tenant.dev.acme",
    "content": "acme_dev",
    "ttl": 60
  }'
```

## Real-World Examples

### E-commerce Platform

#### DNS Setup
```bind
; Customer stores
_tenant.acme-store.com.     IN TXT "acme"
_tenant.bio-shop.org.       IN TXT "bio"
_tenant.startup-market.io.  IN TXT "startup"

; Regional stores
_tenant.acme-eu.com.        IN TXT "acme_eu"
_tenant.acme-us.com.        IN TXT "acme_us"
_tenant.bio-canada.org.     IN TXT "bio_ca"
```

#### Controller Implementation
```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class StoreController extends AbstractController
{
    #[Route('/products', name: 'store_products')]
    public function products(TenantContextInterface $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw $this->createNotFoundException('Store not found');
        }
        
        // Load tenant-specific products
        $products = $this->getProductsForTenant($tenant);
        
        return $this->render('store/products.html.twig', [
            'tenant' => $tenant,
            'products' => $products,
            'store_name' => $tenant->getName(),
        ]);
    }
    
    private function getProductsForTenant($tenant): array
    {
        // Implementation depends on your product entity structure
        return [];
    }
}
```

### SaaS Platform with Custom Domains

#### DNS Setup
```bind
; Premium clients with custom domains
_tenant.portal.acme-corp.com.    IN TXT "acme"
_tenant.app.bio-tech.org.        IN TXT "bio"
_tenant.dashboard.startup.io.    IN TXT "startup"

; Standard clients on shared domain
_tenant.client1.platform.com.    IN TXT "client1"
_tenant.client2.platform.com.    IN TXT "client2"
_tenant.trial.platform.com.      IN TXT "trial_tenant"
```

#### Service Implementation
```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class TenantBrandingService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private DnsTxtTenantResolver $dnsResolver
    ) {}

    public function getBrandingConfig(string $domain): array
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return $this->getDefaultBranding();
        }
        
        // Check if this is a custom domain
        $isCustomDomain = $this->isCustomDomain($domain);
        
        return [
            'tenant_slug' => $tenant->getSlug(),
            'tenant_name' => $tenant->getName(),
            'is_custom_domain' => $isCustomDomain,
            'logo_url' => $this->getLogoUrl($tenant, $isCustomDomain),
            'primary_color' => $this->getPrimaryColor($tenant),
            'custom_css' => $this->getCustomCss($tenant),
        ];
    }
    
    private function isCustomDomain(string $domain): bool
    {
        // Check if domain is not on our platform domains
        $platformDomains = ['platform.com', 'saas.io'];
        
        foreach ($platformDomains as $platformDomain) {
            if (str_ends_with($domain, $platformDomain)) {
                return false;
            }
        }
        
        return true;
    }
    
    private function getDefaultBranding(): array
    {
        return [
            'tenant_slug' => null,
            'tenant_name' => 'Platform',
            'is_custom_domain' => false,
            'logo_url' => '/assets/default-logo.png',
            'primary_color' => '#007bff',
            'custom_css' => null,
        ];
    }
    
    // ... other branding methods
}
```

### Multi-Environment Setup

#### DNS Configuration
```bind
; Production
_tenant.acme.com.           IN TXT "acme"
_tenant.bio.org.            IN TXT "bio"

; Staging
_tenant.staging.acme.com.   IN TXT "acme_staging"
_tenant.staging.bio.org.    IN TXT "bio_staging"

; Development
_tenant.dev.acme.local.     IN TXT "acme_dev"
_tenant.dev.bio.local.      IN TXT "bio_dev"

; Testing
_tenant.test.acme.local.    IN TXT "acme_test"
_tenant.test.bio.local.     IN TXT "bio_test"
```

#### Environment-Aware Service
```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class EnvironmentAwareTenantService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private string $environment
    ) {}

    public function getTenantConfig(): array
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return [];
        }
        
        $config = [
            'tenant_slug' => $tenant->getSlug(),
            'environment' => $this->environment,
            'is_production' => $this->isProduction($tenant),
        ];
        
        // Environment-specific configuration
        switch ($this->environment) {
            case 'prod':
                $config['debug'] = false;
                $config['cache_ttl'] = 3600;
                break;
                
            case 'staging':
                $config['debug'] = true;
                $config['cache_ttl'] = 300;
                break;
                
            case 'dev':
                $config['debug'] = true;
                $config['cache_ttl'] = 0;
                break;
        }
        
        return $config;
    }
    
    private function isProduction($tenant): bool
    {
        // Check if tenant slug doesn't contain environment suffixes
        return !str_contains($tenant->getSlug(), '_dev') 
            && !str_contains($tenant->getSlug(), '_staging')
            && !str_contains($tenant->getSlug(), '_test');
    }
}
```

## DNS Management Services

### DNS Validation Service

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsValidationService
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver
    ) {}

    public function validateTenantDns(string $domain, string $expectedTenant): array
    {
        $result = [
            'domain' => $domain,
            'expected_tenant' => $expectedTenant,
            'is_valid' => false,
            'errors' => [],
            'warnings' => [],
        ];

        try {
            // Check if DNS record exists
            if (!$this->dnsResolver->hasDnsTxtRecord($domain)) {
                $result['errors'][] = 'No DNS TXT record found';
                return $result;
            }

            // Get actual tenant from DNS
            $actualTenant = $this->dnsResolver->getTenantIdentifierFromDns($domain);
            
            if ($actualTenant === null) {
                $result['errors'][] = 'DNS TXT record exists but contains invalid data';
                return $result;
            }

            if ($actualTenant !== $expectedTenant) {
                $result['errors'][] = sprintf(
                    'DNS TXT record contains "%s" but expected "%s"',
                    $actualTenant,
                    $expectedTenant
                );
                return $result;
            }

            $result['is_valid'] = true;
            $result['actual_tenant'] = $actualTenant;

        } catch (\Exception $e) {
            $result['errors'][] = 'DNS query failed: ' . $e->getMessage();
        }

        return $result;
    }

    public function validateMultipleDomains(array $domainMappings): array
    {
        $results = [];
        
        foreach ($domainMappings as $domain => $expectedTenant) {
            $results[$domain] = $this->validateTenantDns($domain, $expectedTenant);
        }
        
        return $results;
    }

    public function generateDnsInstructions(string $domain, string $tenant): array
    {
        $dnsQuery = $this->dnsResolver->getDnsQueryForHost($domain);
        
        return [
            'domain' => $domain,
            'tenant' => $tenant,
            'dns_record' => [
                'name' => $dnsQuery,
                'type' => 'TXT',
                'value' => $tenant,
                'ttl' => 300,
            ],
            'instructions' => [
                'bind' => sprintf('%s IN TXT "%s"', $dnsQuery, $tenant),
                'cloudflare' => [
                    'name' => str_replace('.' . $this->extractBaseDomain($domain), '', $dnsQuery),
                    'content' => $tenant,
                    'type' => 'TXT',
                    'ttl' => 300,
                ],
                'route53' => [
                    'Name' => $dnsQuery,
                    'Type' => 'TXT',
                    'TTL' => 300,
                    'ResourceRecords' => [['Value' => '"' . $tenant . '"']],
                ],
            ],
        ];
    }

    private function extractBaseDomain(string $domain): string
    {
        $parts = explode('.', $domain);
        return implode('.', array_slice($parts, -2));
    }
}
```

### DNS Health Monitoring

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsHealthMonitorService
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver
    ) {}

    public function checkDnsHealth(array $expectedMappings): array
    {
        $results = [
            'overall_status' => 'healthy',
            'total_domains' => count($expectedMappings),
            'healthy_domains' => 0,
            'unhealthy_domains' => 0,
            'details' => [],
        ];

        foreach ($expectedMappings as $domain => $expectedTenant) {
            $domainResult = $this->checkSingleDomain($domain, $expectedTenant);
            $results['details'][$domain] = $domainResult;

            if ($domainResult['status'] === 'healthy') {
                $results['healthy_domains']++;
            } else {
                $results['unhealthy_domains']++;
                $results['overall_status'] = 'unhealthy';
            }
        }

        return $results;
    }

    private function checkSingleDomain(string $domain, string $expectedTenant): array
    {
        $startTime = microtime(true);
        
        try {
            $hasRecord = $this->dnsResolver->hasDnsTxtRecord($domain);
            $actualTenant = $this->dnsResolver->getTenantIdentifierFromDns($domain);
            $responseTime = (microtime(true) - $startTime) * 1000;

            $result = [
                'status' => 'healthy',
                'has_record' => $hasRecord,
                'expected_tenant' => $expectedTenant,
                'actual_tenant' => $actualTenant,
                'is_correct' => $actualTenant === $expectedTenant,
                'response_time_ms' => round($responseTime, 2),
                'errors' => [],
            ];

            if (!$hasRecord) {
                $result['status'] = 'unhealthy';
                $result['errors'][] = 'No DNS TXT record found';
            } elseif ($actualTenant !== $expectedTenant) {
                $result['status'] = 'unhealthy';
                $result['errors'][] = sprintf(
                    'DNS record mismatch: expected "%s", got "%s"',
                    $expectedTenant,
                    $actualTenant
                );
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }
    }
}
```

## Console Commands

### DNS Debug Command

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

#[AsCommand(
    name: 'tenant:debug:dns',
    description: 'Debug DNS TXT record resolution for a domain'
)]
class DebugDnsCommand extends Command
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('domain', InputArgument::REQUIRED, 'Domain to test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domain = $input->getArgument('domain');

        $io->title("DNS TXT Record Debug for: {$domain}");

        // Basic info
        $dnsQuery = $this->dnsResolver->getDnsQueryForHost($domain);
        $io->section('DNS Query Information');
        $io->definitionList(
            ['Domain' => $domain],
            ['DNS Query' => $dnsQuery],
            ['Timeout' => $this->dnsResolver->getDnsTimeout() . ' seconds'],
            ['Cache Enabled' => $this->dnsResolver->isCacheEnabled() ? 'Yes' : 'No']
        );

        // DNS resolution test
        $io->section('DNS Resolution Test');
        
        try {
            $hasRecord = $this->dnsResolver->hasDnsTxtRecord($domain);
            $tenantId = $this->dnsResolver->getTenantIdentifierFromDns($domain);

            if ($hasRecord && $tenantId) {
                $io->success("DNS TXT record found: {$tenantId}");
            } elseif ($hasRecord) {
                $io->warning('DNS TXT record exists but contains invalid data');
            } else {
                $io->error('No DNS TXT record found');
            }

            // Additional DNS details
            $io->section('Raw DNS Query Results');
            $this->performRawDnsQuery($io, $dnsQuery);

        } catch (\Exception $e) {
            $io->error('DNS query failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function performRawDnsQuery(SymfonyStyle $io, string $dnsQuery): void
    {
        try {
            $records = dns_get_record($dnsQuery, DNS_TXT);
            
            if (empty($records)) {
                $io->text('No TXT records found');
                return;
            }

            $io->text('Found TXT records:');
            foreach ($records as $record) {
                $io->text("  - {$record['txt']} (TTL: {$record['ttl']})");
            }

        } catch (\Exception $e) {
            $io->text('Raw DNS query failed: ' . $e->getMessage());
        }
    }
}
```

### DNS Health Check Command

```php
<?php

namespace App\Command;

use App\Service\DnsHealthMonitorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:dns:health-check',
    description: 'Check DNS health for all configured tenant domains'
)]
class DnsHealthCheckCommand extends Command
{
    public function __construct(
        private DnsHealthMonitorService $healthMonitor
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Define expected domain mappings (could be loaded from config)
        $expectedMappings = [
            'acme.com' => 'acme',
            'bio.org' => 'bio',
            'startup.io' => 'startup',
            'client1.saas.com' => 'client1',
            'client2.saas.com' => 'client2',
        ];

        $io->title('DNS Health Check');
        $io->text('Checking DNS TXT records for tenant domains...');

        $results = $this->healthMonitor->checkDnsHealth($expectedMappings);

        // Overall status
        $io->section('Overall Status');
        $statusStyle = $results['overall_status'] === 'healthy' ? 'success' : 'error';
        $io->$statusStyle("Status: {$results['overall_status']}");
        
        $io->definitionList(
            ['Total Domains' => $results['total_domains']],
            ['Healthy Domains' => $results['healthy_domains']],
            ['Unhealthy Domains' => $results['unhealthy_domains']]
        );

        // Detailed results
        $io->section('Domain Details');
        
        foreach ($results['details'] as $domain => $details) {
            $status = $details['status'];
            $statusIcon = $status === 'healthy' ? '✅' : '❌';
            
            $io->text("{$statusIcon} {$domain}");
            
            if ($status === 'healthy') {
                $io->text("  Tenant: {$details['actual_tenant']} ({$details['response_time_ms']}ms)");
            } else {
                foreach ($details['errors'] ?? [$details['error'] ?? 'Unknown error'] as $error) {
                    $io->text("  Error: {$error}");
                }
            }
        }

        return $results['overall_status'] === 'healthy' ? Command::SUCCESS : Command::FAILURE;
    }
}
```

## Testing Examples

### Unit Tests

```php
<?php

namespace App\Tests\Service;

use App\Service\DnsValidationService;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsValidationServiceTest extends TestCase
{
    private DnsTxtTenantResolver $dnsResolver;
    private DnsValidationService $validationService;

    protected function setUp(): void
    {
        $this->dnsResolver = $this->createMock(DnsTxtTenantResolver::class);
        $this->validationService = new DnsValidationService($this->dnsResolver);
    }

    public function testValidateTenantDnsSuccess(): void
    {
        // Arrange
        $domain = 'acme.com';
        $expectedTenant = 'acme';

        $this->dnsResolver->method('hasDnsTxtRecord')
            ->with($domain)
            ->willReturn(true);

        $this->dnsResolver->method('getTenantIdentifierFromDns')
            ->with($domain)
            ->willReturn($expectedTenant);

        // Act
        $result = $this->validationService->validateTenantDns($domain, $expectedTenant);

        // Assert
        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertSame($expectedTenant, $result['actual_tenant']);
    }

    public function testValidateTenantDnsNoRecord(): void
    {
        // Arrange
        $domain = 'unknown.com';
        $expectedTenant = 'unknown';

        $this->dnsResolver->method('hasDnsTxtRecord')
            ->with($domain)
            ->willReturn(false);

        // Act
        $result = $this->validationService->validateTenantDns($domain, $expectedTenant);

        // Assert
        $this->assertFalse($result['is_valid']);
        $this->assertContains('No DNS TXT record found', $result['errors']);
    }

    public function testValidateTenantDnsMismatch(): void
    {
        // Arrange
        $domain = 'acme.com';
        $expectedTenant = 'acme';
        $actualTenant = 'wrong_tenant';

        $this->dnsResolver->method('hasDnsTxtRecord')
            ->with($domain)
            ->willReturn(true);

        $this->dnsResolver->method('getTenantIdentifierFromDns')
            ->with($domain)
            ->willReturn($actualTenant);

        // Act
        $result = $this->validationService->validateTenantDns($domain, $expectedTenant);

        // Assert
        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('wrong_tenant', $result['errors'][0]);
        $this->assertStringContainsString('acme', $result['errors'][0]);
    }
}
```

### Integration Tests

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DnsTxtResolverIntegrationTest extends WebTestCase
{
    public function testDnsBasedTenantResolution(): void
    {
        $client = static::createClient();

        // Test with a domain that should have DNS TXT record
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'test.example.com'
        ]);

        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        
        // Verify tenant was resolved (adjust based on your test DNS setup)
        $this->assertArrayHasKey('tenant', $response);
    }

    public function testDnsResolutionWithUnknownDomain(): void
    {
        $client = static::createClient();

        // Test with a domain that has no DNS TXT record
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'unknown-domain-12345.invalid'
        ]);

        // Should handle gracefully (either 404 or default behavior)
        $this->assertResponseStatusCodeSame(404);
    }
}
```

## Performance Optimization

### DNS Caching Service

```php
<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class CachedDnsResolverService
{
    public function __construct(
        private DnsTxtTenantResolver $dnsResolver,
        private CacheItemPoolInterface $cache,
        private int $cacheTtl = 300
    ) {}

    public function getTenantIdentifierFromDns(string $domain): ?string
    {
        $cacheKey = 'dns_tenant_' . md5($domain);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $tenantId = $this->dnsResolver->getTenantIdentifierFromDns($domain);
        
        $cacheItem->set($tenantId);
        $cacheItem->expiresAfter($this->cacheTtl);
        $this->cache->save($cacheItem);

        return $tenantId;
    }

    public function clearDnsCache(string $domain = null): void
    {
        if ($domain) {
            $cacheKey = 'dns_tenant_' . md5($domain);
            $this->cache->deleteItem($cacheKey);
        } else {
            $this->cache->clear();
        }
    }
}
```

## Best Practices Summary

1. **DNS TTL Management**: Use appropriate TTL values (300s for production, 60s for development)
2. **Error Handling**: Always handle DNS query failures gracefully
3. **Caching**: Implement DNS result caching for better performance
4. **Monitoring**: Set up health checks for critical DNS records
5. **Testing**: Test DNS resolution in different environments
6. **Documentation**: Document all DNS records and their purposes
7. **Security**: Use DNSSEC when possible for production environments
8. **Fallback**: Consider fallback strategies for DNS failures