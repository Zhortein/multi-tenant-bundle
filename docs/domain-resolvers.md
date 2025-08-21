# Domain-Based Tenant Resolvers

The Zhortein Multi-Tenant Bundle provides two advanced tenant resolvers that work with domain names: the **Domain-Based Resolver** and the **Hybrid Domain-Subdomain Resolver**. These resolvers enable sophisticated tenant resolution strategies based on full domain names and domain patterns.

> 📖 **Navigation**: [← Resolver Chain](resolver-chain.md) | [Back to Documentation Index](index.md) | [DNS TXT Resolver →](dns-txt-resolver.md)

## Overview

### Domain-Based Resolver
Resolves tenants based on the complete domain name (e.g., `tenant-one.com`, `acme.org`).

### Hybrid Domain-Subdomain Resolver
Combines domain mapping with subdomain pattern matching for maximum flexibility.

## Domain-Based Tenant Resolver

The `DomainBasedTenantResolver` maps full domain names to specific tenants through configuration.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            tenant-one.com: tenant_one
            acme.org: acme
            bio-corp.net: bio
            startup-platform.io: startup
```

### How It Works

1. **Exact Domain Matching**: The resolver extracts the host from the HTTP request
2. **Normalization**: Removes port information and normalizes case
3. **Mapping Lookup**: Checks if the domain exists in the configured mapping
4. **Tenant Resolution**: Retrieves the tenant using the mapped slug

### Features

- ✅ **Exact Domain Matching**: Maps complete domain names to tenants
- ✅ **Port Handling**: Automatically strips port information (`:8080`, `:443`, etc.)
- ✅ **Case Insensitive**: Handles domain names regardless of case
- ✅ **Simple Configuration**: Straightforward YAML mapping
- ✅ **Fast Resolution**: Direct hash lookup for optimal performance

### Usage Examples

#### Basic Setup

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            # Production domains
            acme-corp.com: acme
            bio-solutions.org: bio
            
            # Development domains
            acme-dev.local: acme
            bio-dev.local: bio
            
            # Custom domains
            client-portal.acme.com: acme
            partner-access.bio.org: bio
```

#### Programmatic Access

```php
use Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver;

class TenantInfoService
{
    public function __construct(
        private DomainBasedTenantResolver $resolver
    ) {}

    public function getDomainInfo(string $domain): array
    {
        return [
            'is_mapped' => $this->resolver->isDomainMapped($domain),
            'tenant_slug' => $this->resolver->getTenantSlugForDomain($domain),
            'all_mappings' => $this->resolver->getDomainMapping(),
        ];
    }
}
```

## Hybrid Domain-Subdomain Resolver

The `HybridDomainSubdomainResolver` provides a two-tier resolution strategy:

1. **Primary**: Exact domain mapping (like the domain resolver)
2. **Fallback**: Subdomain pattern matching with configurable strategies

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        # Exact domain mappings (checked first)
        domain_mapping:
            acme-client.com: acme
            acme-platform.net: acme
            bio-portal.org: bio
            
        # Subdomain pattern mappings (checked second)
        subdomain_mapping:
            # Use subdomain as tenant slug
            '*.myplatform.com': use_subdomain_as_slug
            '*.saas-platform.io': use_subdomain_as_slug
            
            # Map all subdomains to a specific tenant
            '*.shared-platform.net': shared_tenant
            
        # Subdomains to exclude from resolution
        excluded_subdomains:
            - www
            - api
            - admin
            - mail
            - ftp
            - cdn
            - static
```

### Resolution Strategy

The hybrid resolver follows this priority order:

1. **Exact Domain Match**: Check if the full domain is in `domain_mapping`
2. **Subdomain Pattern Match**: Check if the domain matches any pattern in `subdomain_mapping`
3. **Strategy Application**: Apply the configured strategy for the matched pattern

### Subdomain Strategies

#### `use_subdomain_as_slug`
Extracts the subdomain and uses it as the tenant slug.

```yaml
subdomain_mapping:
    '*.myplatform.com': use_subdomain_as_slug
```

**Examples:**
- `acme.myplatform.com` → tenant slug: `acme`
- `bio.myplatform.com` → tenant slug: `bio`
- `startup.myplatform.com` → tenant slug: `startup`

#### Fixed Tenant Strategy
Maps all matching subdomains to a specific tenant.

```yaml
subdomain_mapping:
    '*.shared-platform.net': shared_tenant
```

**Examples:**
- `client1.shared-platform.net` → tenant slug: `shared_tenant`
- `client2.shared-platform.net` → tenant slug: `shared_tenant`
- `anything.shared-platform.net` → tenant slug: `shared_tenant`

### Features

- ✅ **Dual Resolution**: Domain mapping + subdomain patterns
- ✅ **Priority System**: Domain mapping takes precedence over subdomain patterns
- ✅ **Flexible Patterns**: Wildcard subdomain matching
- ✅ **Multiple Strategies**: Different resolution strategies per pattern
- ✅ **Exclusion Lists**: Skip common subdomains (www, api, etc.)
- ✅ **Nested Subdomain Protection**: Ignores complex subdomain structures

### Advanced Configuration Examples

#### Multi-Environment Setup

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # Production domains
            acme-prod.com: acme
            bio-prod.org: bio
            
            # Staging domains
            acme-staging.com: acme
            bio-staging.org: bio
            
        subdomain_mapping:
            # Development environment
            '*.dev.myplatform.com': use_subdomain_as_slug
            
            # Testing environment
            '*.test.myplatform.com': use_subdomain_as_slug
            
            # Demo environment - all use demo tenant
            '*.demo.myplatform.com': demo_tenant
```

#### White-Label Platform

```yaml
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        domain_mapping:
            # Premium clients with custom domains
            client-portal.acme.com: acme
            partner-access.bio.org: bio
            enterprise-dashboard.startup.io: startup
            
        subdomain_mapping:
            # Standard clients on shared domain
            '*.platform.com': use_subdomain_as_slug
            '*.saas.io': use_subdomain_as_slug
            
        excluded_subdomains:
            - www
            - api
            - admin
            - support
            - help
            - docs
            - status
```

### Programmatic Usage

```php
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;

class TenantAnalyticsService
{
    public function __construct(
        private HybridDomainSubdomainResolver $resolver
    ) {}

    public function analyzeDomain(string $domain): array
    {
        return [
            'domain_mapped' => $this->resolver->isDomainMapped($domain),
            'subdomain_pattern_match' => $this->resolver->matchesSubdomainPattern($domain),
            'resolution_type' => $this->getResolutionType($domain),
            'domain_mappings' => $this->resolver->getDomainMapping(),
            'subdomain_patterns' => $this->resolver->getSubdomainMapping(),
            'excluded_subdomains' => $this->resolver->getExcludedSubdomains(),
        ];
    }
    
    private function getResolutionType(string $domain): string
    {
        if ($this->resolver->isDomainMapped($domain)) {
            return 'exact_domain';
        }
        
        if ($this->resolver->matchesSubdomainPattern($domain)) {
            return 'subdomain_pattern';
        }
        
        return 'no_match';
    }
}
```

## Testing Your Configuration

### Unit Testing

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TenantResolutionTest extends TestCase
{
    public function testDomainResolution(): void
    {
        $request = Request::create('https://acme-client.com/dashboard');
        $tenant = $this->resolver->resolveTenant($request);
        
        $this->assertNotNull($tenant);
        $this->assertSame('acme', $tenant->getSlug());
    }
    
    public function testSubdomainResolution(): void
    {
        $request = Request::create('https://bio.myplatform.com/api');
        $tenant = $this->resolver->resolveTenant($request);
        
        $this->assertNotNull($tenant);
        $this->assertSame('bio', $tenant->getSlug());
    }
}
```

### Manual Testing

```bash
# Test domain resolution
curl -H "Host: acme-client.com" http://localhost:8000/api/tenant/current

# Test subdomain resolution
curl -H "Host: bio.myplatform.com" http://localhost:8000/api/tenant/current

# Test excluded subdomain (should not resolve)
curl -H "Host: www.myplatform.com" http://localhost:8000/api/tenant/current
```

## Performance Considerations

### Domain Resolver Performance
- **O(1) lookup**: Direct hash table access
- **Memory usage**: Minimal - only stores the mapping array
- **Best for**: Known, finite set of domains

### Hybrid Resolver Performance
- **Domain mapping**: O(1) lookup (checked first)
- **Pattern matching**: O(n) where n = number of patterns
- **Memory usage**: Moderate - stores mappings + patterns
- **Best for**: Mixed scenarios with both custom domains and subdomain patterns

### Optimization Tips

1. **Prioritize Domain Mapping**: Put frequently accessed domains in `domain_mapping`
2. **Limit Patterns**: Keep `subdomain_mapping` patterns to a minimum
3. **Cache Results**: Consider implementing tenant caching for high-traffic scenarios
4. **Monitor Performance**: Use profiling tools to measure resolution time

## Migration Guide

### From Subdomain Resolver

```yaml
# Old configuration
zhortein_multi_tenant:
    resolver: 'subdomain'
    subdomain:
        base_domain: 'myplatform.com'

# New hybrid configuration
zhortein_multi_tenant:
    resolver: 'hybrid'
    hybrid:
        subdomain_mapping:
            '*.myplatform.com': use_subdomain_as_slug
```

### From Path Resolver

```yaml
# Old configuration
zhortein_multi_tenant:
    resolver: 'path'

# New domain configuration for custom domains
zhortein_multi_tenant:
    resolver: 'domain'
    domain:
        domain_mapping:
            acme.com: acme
            bio.org: bio
```

## Troubleshooting

### Common Issues

1. **Domain Not Resolving**
   - Check domain mapping configuration
   - Verify domain normalization (case, ports)
   - Ensure tenant exists in registry

2. **Subdomain Pattern Not Matching**
   - Verify pattern syntax (`*.domain.com`)
   - Check excluded subdomains list
   - Test pattern matching logic

3. **Wrong Tenant Resolved**
   - Check resolution priority (domain vs subdomain)
   - Verify mapping configuration
   - Test with different domain formats

### Debug Configuration

```yaml
# Enable debug logging
monolog:
    handlers:
        main:
            level: debug
            channels: ["zhortein_multi_tenant"]
```

### Validation Commands

```bash
# Validate configuration
php bin/console debug:config zhortein_multi_tenant

# Test tenant resolution
php bin/console tenant:resolve --domain=acme-client.com
php bin/console tenant:resolve --domain=bio.myplatform.com
```

## Best Practices

1. **Domain Mapping Priority**: Use exact domain mapping for known, stable domains
2. **Pattern Efficiency**: Keep subdomain patterns simple and specific
3. **Exclusion Lists**: Maintain comprehensive excluded subdomain lists
4. **Testing**: Test all domain/subdomain combinations thoroughly
5. **Documentation**: Document your domain strategy for team members
6. **Monitoring**: Monitor resolution performance and success rates
7. **Security**: Validate domain inputs to prevent injection attacks
8. **Caching**: Consider caching resolved tenants for better performance

## Security Considerations

- **Domain Validation**: The resolvers automatically normalize and validate domains
- **Injection Prevention**: Domain inputs are sanitized during normalization
- **Pattern Safety**: Subdomain patterns are converted to safe regex patterns
- **Registry Integration**: All tenant lookups go through the secure tenant registry

## Examples Repository

For complete working examples, see:
- [Domain Resolver Examples](examples/domain-resolver-usage.md)
- [Hybrid Resolver Examples](examples/hybrid-resolver-usage.md)
- [Multi-Environment Setup](examples/multi-environment-domains.md)