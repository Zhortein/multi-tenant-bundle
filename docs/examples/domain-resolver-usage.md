# Domain-Based Resolver Usage Examples

This document provides practical examples of using the Domain-Based and Hybrid Domain-Subdomain resolvers in real-world scenarios.

## Domain-Based Resolver Examples

### Basic E-commerce Platform

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            # Client stores
            acme-store.com: acme
            bio-shop.org: bio
            startup-market.io: startup
            
            # Regional domains
            acme-eu.com: acme
            acme-us.com: acme
            bio-canada.org: bio
```

### Multi-Brand Corporate Setup

```yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            # Main brands
            acme-corp.com: acme
            acme-solutions.com: acme
            bio-technologies.org: bio
            bio-research.org: bio
            
            # Subsidiary brands
            acme-consulting.net: acme_consulting
            bio-pharma.com: bio_pharma
            
            # Partner portals
            partners.acme.com: acme_partners
            suppliers.bio.org: bio_suppliers
```

### Development and Staging Environments

```yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            # Production
            acme.com: acme
            bio.org: bio
            
            # Staging
            staging-acme.com: acme
            staging-bio.org: bio
            
            # Development
            dev-acme.local: acme
            dev-bio.local: bio
            
            # Testing
            test-acme.local: acme
            test-bio.local: bio
```

## Hybrid Resolver Examples

### SaaS Platform with Custom Domains

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        # Premium clients with custom domains
        domain_mapping:
            # Enterprise clients
            portal.acme-corp.com: acme
            dashboard.bio-tech.org: bio
            app.startup-inc.io: startup
            
            # White-label solutions
            client-app.acme.com: acme
            partner-portal.bio.org: bio
            
        # Standard clients on shared domain
        subdomain_mapping:
            '*.myplatform.com': use_subdomain_as_slug
            '*.saas-app.io': use_subdomain_as_slug
            
        excluded_subdomains:
            - www
            - api
            - admin
            - support
            - help
            - docs
```

**Resolution Examples:**
- `portal.acme-corp.com` → `acme` (domain mapping)
- `acme.myplatform.com` → `acme` (subdomain pattern)
- `bio.saas-app.io` → `bio` (subdomain pattern)
- `www.myplatform.com` → `null` (excluded subdomain)

### Multi-Environment SaaS

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # Production custom domains
            app.acme.com: acme
            portal.bio.org: bio
            
            # Staging custom domains
            staging-app.acme.com: acme
            staging-portal.bio.org: bio
            
        subdomain_mapping:
            # Production shared domain
            '*.platform.com': use_subdomain_as_slug
            
            # Staging shared domain
            '*.staging.platform.com': use_subdomain_as_slug
            
            # Development environment
            '*.dev.platform.com': use_subdomain_as_slug
            
            # Demo environment - all use demo tenant
            '*.demo.platform.com': demo_tenant
            
        excluded_subdomains:
            - www
            - api
            - admin
            - cdn
            - static
            - assets
```

### Educational Platform

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # University partnerships
            learn.harvard.edu: harvard
            courses.mit.edu: mit
            training.stanford.edu: stanford
            
            # Corporate training
            academy.acme.com: acme_academy
            learning.bio.org: bio_learning
            
        subdomain_mapping:
            # Schools on shared domain
            '*.eduplatform.com': use_subdomain_as_slug
            
            # Districts
            '*.k12.eduplatform.com': use_subdomain_as_slug
            
            # Demo schools
            '*.demo.eduplatform.com': demo_school
```

## Advanced Configuration Examples

### Geographic Distribution

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # Regional domains
            acme-us.com: acme
            acme-eu.com: acme
            acme-asia.com: acme
            bio-americas.org: bio
            bio-europe.org: bio
            
            # Country-specific
            acme.co.uk: acme_uk
            acme.de: acme_germany
            bio.fr: bio_france
            
        subdomain_mapping:
            # Regional subdomains
            '*.us.platform.com': use_subdomain_as_slug
            '*.eu.platform.com': use_subdomain_as_slug
            '*.asia.platform.com': use_subdomain_as_slug
```

### Microservices Architecture

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # API gateways
            api.acme.com: acme
            api.bio.org: bio
            
            # Service-specific domains
            auth.acme.com: acme
            billing.acme.com: acme
            analytics.bio.org: bio
            reports.bio.org: bio
            
        subdomain_mapping:
            # Tenant-specific services
            '*.services.platform.com': use_subdomain_as_slug
            '*.api.platform.com': use_subdomain_as_slug
            
        excluded_subdomains:
            - www
            - cdn
            - static
            - health
            - status
            - monitoring
```

## Service Integration Examples

### Controller Usage

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantInfoController extends AbstractController
{
    public function __construct(
        private TenantContextInterface $tenantContext
    ) {}

    public function getCurrentTenant(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return $this->json(['error' => 'No tenant resolved'], 404);
        }
        
        return $this->json([
            'tenant' => [
                'slug' => $tenant->getSlug(),
                'name' => $tenant->getName(),
                'domain' => $request->getHost(),
            ],
            'resolution' => [
                'strategy' => 'domain', // or 'hybrid'
                'host' => $request->getHost(),
                'normalized_host' => strtolower($request->getHost()),
            ]
        ]);
    }
}
```

### Custom Domain Service

```php
use Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;

class CustomDomainService
{
    public function __construct(
        private DomainBasedTenantResolver|HybridDomainSubdomainResolver $resolver
    ) {}

    public function validateCustomDomain(string $domain, string $tenantSlug): bool
    {
        // Check if domain is already mapped
        if ($this->resolver->isDomainMapped($domain)) {
            $existingSlug = $this->resolver->getTenantSlugForDomain($domain);
            return $existingSlug === $tenantSlug;
        }
        
        // Domain is available for mapping
        return true;
    }
    
    public function getDomainInfo(string $domain): array
    {
        $info = [
            'domain' => $domain,
            'is_mapped' => false,
            'tenant_slug' => null,
            'resolution_type' => 'none',
        ];
        
        if ($this->resolver instanceof DomainBasedTenantResolver) {
            $info['is_mapped'] = $this->resolver->isDomainMapped($domain);
            $info['tenant_slug'] = $this->resolver->getTenantSlugForDomain($domain);
            $info['resolution_type'] = $info['is_mapped'] ? 'domain' : 'none';
        } elseif ($this->resolver instanceof HybridDomainSubdomainResolver) {
            if ($this->resolver->isDomainMapped($domain)) {
                $info['is_mapped'] = true;
                $info['resolution_type'] = 'domain';
            } elseif ($this->resolver->matchesSubdomainPattern($domain)) {
                $info['is_mapped'] = true;
                $info['resolution_type'] = 'subdomain_pattern';
            }
        }
        
        return $info;
    }
}
```

### Tenant Analytics Service

```php
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;

class TenantAnalyticsService
{
    public function __construct(
        private HybridDomainSubdomainResolver $resolver
    ) {}

    public function getResolutionStats(): array
    {
        $domainMappings = $this->resolver->getDomainMapping();
        $subdomainMappings = $this->resolver->getSubdomainMapping();
        
        return [
            'total_domain_mappings' => count($domainMappings),
            'total_subdomain_patterns' => count($subdomainMappings),
            'tenants_by_domain' => array_count_values($domainMappings),
            'subdomain_strategies' => array_count_values($subdomainMappings),
            'excluded_subdomains' => $this->resolver->getExcludedSubdomains(),
        ];
    }
    
    public function analyzeTraffic(array $requestLogs): array
    {
        $stats = [
            'domain_resolutions' => 0,
            'subdomain_resolutions' => 0,
            'failed_resolutions' => 0,
            'top_domains' => [],
        ];
        
        foreach ($requestLogs as $log) {
            $domain = $log['host'];
            
            if ($this->resolver->isDomainMapped($domain)) {
                $stats['domain_resolutions']++;
            } elseif ($this->resolver->matchesSubdomainPattern($domain)) {
                $stats['subdomain_resolutions']++;
            } else {
                $stats['failed_resolutions']++;
            }
            
            $stats['top_domains'][$domain] = ($stats['top_domains'][$domain] ?? 0) + 1;
        }
        
        arsort($stats['top_domains']);
        $stats['top_domains'] = array_slice($stats['top_domains'], 0, 10, true);
        
        return $stats;
    }
}
```

## Testing Examples

### Functional Tests

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DomainResolutionTest extends WebTestCase
{
    public function testDomainBasedResolution(): void
    {
        $client = static::createClient();
        
        // Test exact domain mapping
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'acme-client.com'
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('acme', $response['tenant']['slug']);
    }
    
    public function testHybridResolution(): void
    {
        $client = static::createClient();
        
        // Test domain mapping priority
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'portal.acme-corp.com'
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('acme', $response['tenant']['slug']);
        
        // Test subdomain pattern
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'bio.myplatform.com'
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('bio', $response['tenant']['slug']);
    }
    
    public function testExcludedSubdomain(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/tenant/current', [], [], [
            'HTTP_HOST' => 'www.myplatform.com'
        ]);
        
        $this->assertResponseStatusCodeSame(404);
    }
}
```

### Unit Tests for Custom Services

```php
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;

class CustomDomainServiceTest extends TestCase
{
    private HybridDomainSubdomainResolver $resolver;
    private CustomDomainService $service;
    
    protected function setUp(): void
    {
        $this->resolver = $this->createMock(HybridDomainSubdomainResolver::class);
        $this->service = new CustomDomainService($this->resolver);
    }
    
    public function testValidateCustomDomainAvailable(): void
    {
        $this->resolver->method('isDomainMapped')
            ->with('new-domain.com')
            ->willReturn(false);
            
        $result = $this->service->validateCustomDomain('new-domain.com', 'acme');
        
        $this->assertTrue($result);
    }
    
    public function testValidateCustomDomainAlreadyMapped(): void
    {
        $this->resolver->method('isDomainMapped')
            ->with('existing-domain.com')
            ->willReturn(true);
            
        $this->resolver->method('getTenantSlugForDomain')
            ->with('existing-domain.com')
            ->willReturn('acme');
            
        $result = $this->service->validateCustomDomain('existing-domain.com', 'acme');
        
        $this->assertTrue($result);
    }
}
```

## Performance Testing

### Load Testing Script

```php
use Symfony\Component\HttpFoundation\Request;

class DomainResolverPerformanceTest
{
    private array $testDomains = [
        'acme-client.com',
        'bio-portal.org',
        'tenant1.myplatform.com',
        'tenant2.myplatform.com',
        'www.myplatform.com', // Should be excluded
        'unknown-domain.com', // Should not resolve
    ];
    
    public function benchmarkResolution(int $iterations = 10000): array
    {
        $results = [];
        
        foreach ($this->testDomains as $domain) {
            $start = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                $request = Request::create("https://{$domain}/test");
                $this->resolver->resolveTenant($request);
            }
            
            $end = microtime(true);
            $results[$domain] = [
                'total_time' => $end - $start,
                'avg_time' => ($end - $start) / $iterations,
                'requests_per_second' => $iterations / ($end - $start),
            ];
        }
        
        return $results;
    }
}
```

## Monitoring and Debugging

### Custom Debug Command

```php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugDomainResolutionCommand extends Command
{
    protected static $defaultName = 'tenant:debug:domain';
    
    public function __construct(
        private HybridDomainSubdomainResolver $resolver
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->setDescription('Debug domain resolution for a given domain')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain to test');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = $input->getArgument('domain');
        
        $output->writeln("Debugging domain resolution for: {$domain}");
        $output->writeln('');
        
        // Test domain mapping
        $isDomainMapped = $this->resolver->isDomainMapped($domain);
        $output->writeln("Domain mapped: " . ($isDomainMapped ? 'YES' : 'NO'));
        
        if ($isDomainMapped) {
            $tenantSlug = $this->resolver->getTenantSlugForDomain($domain);
            $output->writeln("Mapped to tenant: {$tenantSlug}");
        }
        
        // Test subdomain pattern
        $matchesPattern = $this->resolver->matchesSubdomainPattern($domain);
        $output->writeln("Matches subdomain pattern: " . ($matchesPattern ? 'YES' : 'NO'));
        
        // Show configuration
        $output->writeln('');
        $output->writeln('Domain mappings:');
        foreach ($this->resolver->getDomainMapping() as $d => $slug) {
            $output->writeln("  {$d} -> {$slug}");
        }
        
        $output->writeln('');
        $output->writeln('Subdomain patterns:');
        foreach ($this->resolver->getSubdomainMapping() as $pattern => $strategy) {
            $output->writeln("  {$pattern} -> {$strategy}");
        }
        
        return Command::SUCCESS;
    }
}
```

## Best Practices Summary

1. **Domain Mapping Priority**: Use exact domain mapping for known, stable domains
2. **Pattern Efficiency**: Keep subdomain patterns simple and specific
3. **Testing Coverage**: Test all domain/subdomain combinations
4. **Performance Monitoring**: Monitor resolution times and success rates
5. **Configuration Management**: Use environment-specific configurations
6. **Security**: Validate and sanitize domain inputs
7. **Documentation**: Document your domain strategy clearly
8. **Caching**: Consider implementing tenant caching for high-traffic scenarios