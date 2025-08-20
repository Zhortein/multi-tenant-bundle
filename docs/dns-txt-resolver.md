# DNS TXT Record Tenant Resolver

The DNS TXT Record Tenant Resolver is an advanced tenant resolution strategy that uses DNS TXT records to determine tenant information. This resolver queries DNS TXT records for the pattern `_tenant.<domain>` to retrieve tenant identifiers, making it ideal for distributed systems and multi-domain setups where DNS control is available.

> 📖 **Navigation**: [← Domain Resolvers](domain-resolvers.md) | [Back to Documentation Index](index.md) | [Tenant Context →](tenant-context.md)

## Overview

The `DnsTxtTenantResolver` provides a DNS-based approach to tenant resolution that offers several advantages:

- **Decentralized Configuration**: Tenant mapping is stored in DNS, not in application configuration
- **Dynamic Updates**: DNS changes propagate without application restarts
- **Multi-Domain Support**: Works seamlessly across different domains and subdomains
- **Infrastructure Integration**: Leverages existing DNS infrastructure
- **Scalability**: DNS caching provides excellent performance characteristics

## How It Works

1. **DNS Query Construction**: For a request to `acme.com`, the resolver queries `_tenant.acme.com`
2. **TXT Record Lookup**: Performs a DNS TXT record query using system DNS functions
3. **Identifier Extraction**: Extracts the tenant identifier from the TXT record value
4. **Tenant Resolution**: Uses the tenant registry to resolve the actual tenant object
5. **Caching**: Optionally caches DNS results for improved performance

## Configuration

### Basic Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5          # DNS query timeout in seconds (1-30)
        enable_cache: true  # Enable DNS result caching
```

### Advanced Configuration

```yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 10         # Longer timeout for slower DNS servers
        enable_cache: false # Disable caching for development
```

## DNS Record Setup

### DNS Record Format

The resolver looks for TXT records with the following pattern:
```
_tenant.<domain> IN TXT "<tenant_identifier>"
```

### Examples

#### BIND DNS Configuration

```bind
; Zone file for example.com
$TTL 300

; Tenant records
_tenant.acme.com.           IN TXT "acme"
_tenant.bio-corp.com.       IN TXT "bio"
_tenant.startup.example.com. IN TXT "startup"
_tenant.client1.saas.com.   IN TXT "client1"

; Subdomain tenant records
_tenant.tenant1.platform.com. IN TXT "tenant1"
_tenant.tenant2.platform.com. IN TXT "tenant2"
```

#### Cloudflare DNS Setup

Using Cloudflare DNS interface or API:

```bash
# Using Cloudflare API
curl -X POST "https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records" \
  -H "Authorization: Bearer {api_token}" \
  -H "Content-Type: application/json" \
  --data '{
    "type": "TXT",
    "name": "_tenant.acme.example.com",
    "content": "acme",
    "ttl": 300
  }'
```

Via Cloudflare Dashboard:
1. Go to DNS management for your domain
2. Add a TXT record:
   - **Name**: `_tenant.acme`
   - **Content**: `acme`
   - **TTL**: `5 minutes` (300 seconds)

#### AWS Route 53 Setup

```json
{
  "Changes": [
    {
      "Action": "CREATE",
      "ResourceRecordSet": {
        "Name": "_tenant.acme.example.com",
        "Type": "TXT",
        "TTL": 300,
        "ResourceRecords": [
          {
            "Value": "\"acme\""
          }
        ]
      }
    }
  ]
}
```

#### OVH DNS Setup

Using OVH API:

```bash
curl -X POST "https://eu.api.ovh.com/1.0/domain/zone/example.com/record" \
  -H "Content-Type: application/json" \
  -d '{
    "fieldType": "TXT",
    "subDomain": "_tenant.acme",
    "target": "acme",
    "ttl": 300
  }'
```

#### Google Cloud DNS Setup

```bash
gcloud dns record-sets transaction start --zone=example-zone

gcloud dns record-sets transaction add "acme" \
  --name="_tenant.acme.example.com." \
  --ttl=300 \
  --type=TXT \
  --zone=example-zone

gcloud dns record-sets transaction execute --zone=example-zone
```

## Usage Examples

### Multi-Domain E-commerce Platform

```yaml
# DNS Records Setup
# _tenant.acme-store.com     TXT "acme"
# _tenant.bio-shop.org       TXT "bio"
# _tenant.startup-market.io  TXT "startup"

zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
```

**Resolution Examples:**
- `https://acme-store.com/products` → tenant: `acme`
- `https://bio-shop.org/catalog` → tenant: `bio`
- `https://startup-market.io/dashboard` → tenant: `startup`

### SaaS Platform with Subdomains

```yaml
# DNS Records Setup
# _tenant.client1.platform.com  TXT "client1"
# _tenant.client2.platform.com  TXT "client2"
# _tenant.demo.platform.com     TXT "demo"

zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 3
        enable_cache: true
```

### White-Label Solution

```yaml
# DNS Records Setup
# _tenant.portal.acme.com        TXT "acme"
# _tenant.app.bio-tech.org       TXT "bio"
# _tenant.dashboard.startup.io   TXT "startup"

zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
```

## Service Integration

### Controller Usage

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class TenantInfoController extends AbstractController
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private DnsTxtTenantResolver $dnsResolver
    ) {}

    public function getCurrentTenant(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $host = $request->getHost();
        
        return $this->json([
            'tenant' => $tenant ? [
                'slug' => $tenant->getSlug(),
                'name' => $tenant->getName(),
            ] : null,
            'dns_info' => [
                'host' => $host,
                'dns_query' => $this->dnsResolver->getDnsQueryForHost($host),
                'has_txt_record' => $this->dnsResolver->hasDnsTxtRecord($host),
                'txt_value' => $this->dnsResolver->getTenantIdentifierFromDns($host),
            ],
            'resolver_config' => [
                'timeout' => $this->dnsResolver->getDnsTimeout(),
                'cache_enabled' => $this->dnsResolver->isCacheEnabled(),
            ]
        ]);
    }
}
```

### DNS Management Service

```php
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsTenantManagementService
{
    public function __construct(
        private DnsTxtTenantResolver $resolver
    ) {}

    public function validateDnsSetup(string $domain, string $expectedTenant): array
    {
        $result = [
            'domain' => $domain,
            'expected_tenant' => $expectedTenant,
            'dns_query' => $this->resolver->getDnsQueryForHost($domain),
            'has_record' => false,
            'actual_tenant' => null,
            'is_valid' => false,
            'errors' => [],
        ];

        try {
            $result['has_record'] = $this->resolver->hasDnsTxtRecord($domain);
            
            if ($result['has_record']) {
                $result['actual_tenant'] = $this->resolver->getTenantIdentifierFromDns($domain);
                $result['is_valid'] = $result['actual_tenant'] === $expectedTenant;
                
                if (!$result['is_valid']) {
                    $result['errors'][] = sprintf(
                        'DNS TXT record contains "%s" but expected "%s"',
                        $result['actual_tenant'],
                        $expectedTenant
                    );
                }
            } else {
                $result['errors'][] = 'No DNS TXT record found';
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'DNS query failed: ' . $e->getMessage();
        }

        return $result;
    }

    public function generateDnsInstructions(string $domain, string $tenant): array
    {
        $dnsQuery = $this->resolver->getDnsQueryForHost($domain);
        
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
                ],
                'route53' => [
                    'Name' => $dnsQuery,
                    'Type' => 'TXT',
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

### Health Check Service

```php
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsHealthCheckService
{
    public function __construct(
        private DnsTxtTenantResolver $resolver
    ) {}

    public function checkDnsHealth(array $domains): array
    {
        $results = [];
        
        foreach ($domains as $domain => $expectedTenant) {
            $startTime = microtime(true);
            
            try {
                $hasRecord = $this->resolver->hasDnsTxtRecord($domain);
                $actualTenant = $this->resolver->getTenantIdentifierFromDns($domain);
                $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                
                $results[$domain] = [
                    'status' => 'healthy',
                    'has_record' => $hasRecord,
                    'expected_tenant' => $expectedTenant,
                    'actual_tenant' => $actualTenant,
                    'is_correct' => $actualTenant === $expectedTenant,
                    'response_time_ms' => round($responseTime, 2),
                ];
            } catch (\Exception $e) {
                $results[$domain] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ];
            }
        }
        
        return $results;
    }
}
```

## Testing

### Unit Testing

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

class DnsTxtResolverTest extends TestCase
{
    public function testDnsResolution(): void
    {
        // Mock the resolver to avoid actual DNS queries in tests
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->method('queryDnsTxtRecord')
            ->with('acme.com')
            ->willReturn('acme');

        $request = Request::create('https://acme.com/dashboard');
        $tenant = $resolver->resolveTenant($request);
        
        $this->assertNotNull($tenant);
        $this->assertSame('acme', $tenant->getSlug());
    }
}
```

### Integration Testing

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DnsIntegrationTest extends WebTestCase
{
    public function testDnsBasedTenantResolution(): void
    {
        $client = static::createClient();
        
        // Test with a domain that has DNS TXT record configured
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'test-tenant.example.com'
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('test_tenant', $response['tenant']['slug']);
    }
}
```

### Manual DNS Testing

```bash
# Test DNS TXT record resolution
dig +short TXT _tenant.acme.com

# Expected output: "acme"

# Test with different DNS servers
dig @8.8.8.8 +short TXT _tenant.acme.com
dig @1.1.1.1 +short TXT _tenant.acme.com

# Test DNS propagation
for server in 8.8.8.8 1.1.1.1 208.67.222.222; do
    echo "Testing $server:"
    dig @$server +short TXT _tenant.acme.com
done
```

## Performance Considerations

### DNS Caching

The resolver supports DNS result caching to improve performance:

```yaml
zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        enable_cache: true  # Enable caching
        timeout: 5
```

### TTL Configuration

Set appropriate TTL values for your DNS records:

- **Production**: 300-3600 seconds (5 minutes to 1 hour)
- **Development**: 60-300 seconds (1-5 minutes)
- **Testing**: 60 seconds or less

### Performance Monitoring

```php
class DnsPerformanceMonitor
{
    public function measureDnsPerformance(array $domains): array
    {
        $results = [];
        
        foreach ($domains as $domain) {
            $startTime = microtime(true);
            
            // Perform DNS query
            $records = dns_get_record("_tenant.{$domain}", DNS_TXT);
            
            $endTime = microtime(true);
            $responseTime = ($endTime - $startTime) * 1000;
            
            $results[$domain] = [
                'response_time_ms' => round($responseTime, 2),
                'record_count' => count($records),
                'has_record' => !empty($records),
            ];
        }
        
        return $results;
    }
}
```

## Security Considerations

### DNS Security

- **DNSSEC**: Use DNSSEC to prevent DNS spoofing attacks
- **Validation**: The resolver validates tenant identifiers (alphanumeric, hyphens, underscores only)
- **Sanitization**: All DNS responses are sanitized before use
- **Timeout**: DNS queries have configurable timeouts to prevent hanging

### Access Control

```php
// Example: Restrict DNS-based resolution to specific environments
if ($this->environment === 'prod' && !$this->isDnssecEnabled($domain)) {
    throw new SecurityException('DNSSEC required for production DNS resolution');
}
```

## Troubleshooting

### Common Issues

#### 1. DNS Record Not Found

**Symptoms**: Resolver returns `null`, no tenant resolved

**Solutions**:
```bash
# Check if DNS record exists
dig +short TXT _tenant.yourdomain.com

# Check DNS propagation
dig @8.8.8.8 TXT _tenant.yourdomain.com
```

#### 2. DNS Timeout

**Symptoms**: Slow response times, timeout errors

**Solutions**:
```yaml
# Increase timeout
zhortein_multi_tenant:
    dns_txt:
        timeout: 10  # Increase from default 5 seconds
```

#### 3. Invalid Tenant Identifier

**Symptoms**: DNS record exists but tenant not resolved

**Check**: Ensure tenant identifier contains only valid characters:
```bash
# Valid: acme, bio-corp, tenant_123
# Invalid: tenant.with.dots, tenant@domain.com
```

#### 4. DNS Propagation Delays

**Symptoms**: Inconsistent resolution results

**Solutions**:
- Wait for DNS propagation (up to 48 hours globally)
- Use lower TTL values during testing
- Test with multiple DNS servers

### Debug Commands

```bash
# Debug DNS resolution
php bin/console tenant:debug:dns yourdomain.com

# Test specific DNS servers
php bin/console tenant:debug:dns yourdomain.com --dns-server=8.8.8.8

# Check DNS propagation
php bin/console tenant:debug:dns-propagation yourdomain.com
```

### Logging Configuration

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        dns_resolver:
            type: stream
            path: '%kernel.logs_dir%/dns_resolver.log'
            level: debug
            channels: ['zhortein_multi_tenant.dns']
```

## Best Practices

### DNS Management

1. **Use Short TTLs During Setup**: Start with 60-300 second TTLs for testing
2. **Increase TTLs for Production**: Use 3600+ seconds for stable production environments
3. **Monitor DNS Health**: Implement health checks for critical DNS records
4. **Use DNSSEC**: Enable DNSSEC for production environments
5. **Multiple DNS Providers**: Consider using multiple DNS providers for redundancy

### Application Configuration

1. **Configure Timeouts**: Set appropriate DNS query timeouts
2. **Enable Caching**: Use DNS caching for better performance
3. **Error Handling**: Implement graceful fallbacks for DNS failures
4. **Monitoring**: Monitor DNS resolution performance and success rates

### Development Workflow

1. **Local DNS**: Use local DNS server or hosts file for development
2. **Testing**: Test DNS changes in staging environment first
3. **Rollback Plan**: Have a rollback plan for DNS changes
4. **Documentation**: Document all DNS records and their purposes

## Migration Guide

### From Other Resolvers

#### From Subdomain Resolver

```yaml
# Old configuration
zhortein_multi_tenant:
    resolver: 'subdomain'
    subdomain:
        base_domain: 'platform.com'

# New DNS TXT configuration
# Set up DNS records:
# _tenant.tenant1.platform.com TXT "tenant1"
# _tenant.tenant2.platform.com TXT "tenant2"

zhortein_multi_tenant:
    resolver: 'dns_txt'
    dns_txt:
        timeout: 5
        enable_cache: true
```

#### From Domain Resolver

```yaml
# Old configuration
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            acme.com: acme
            bio.org: bio

# New DNS TXT configuration
# Set up DNS records:
# _tenant.acme.com TXT "acme"
# _tenant.bio.org TXT "bio"

zhortein_multi_tenant:
    resolver: 'dns_txt'
```

## Advanced Use Cases

### Multi-Region Setup

```bash
# Different DNS records for different regions
_tenant.us.acme.com     TXT "acme_us"
_tenant.eu.acme.com     TXT "acme_eu"
_tenant.asia.acme.com   TXT "acme_asia"
```

### Environment-Specific Tenants

```bash
# Environment-specific tenant resolution
_tenant.dev.acme.com     TXT "acme_dev"
_tenant.staging.acme.com TXT "acme_staging"
_tenant.prod.acme.com    TXT "acme"
```

### Load Balancing Integration

```bash
# Use DNS TXT records with load balancer health checks
_tenant.lb1.acme.com TXT "acme"
_tenant.lb2.acme.com TXT "acme"
```

The DNS TXT resolver provides a powerful, scalable, and flexible approach to tenant resolution that leverages existing DNS infrastructure while providing excellent performance and reliability characteristics.