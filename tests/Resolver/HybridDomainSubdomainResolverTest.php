<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\HybridDomainSubdomainResolver
 */
class HybridDomainSubdomainResolverTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private HybridDomainSubdomainResolver $resolver;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);

        $domainMapping = [
            'acme-client.com' => 'acme',
            'acme-platform.net' => 'acme',
            'bio-corp.org' => 'bio',
        ];

        $subdomainMapping = [
            '*.myplatform.com' => 'use_subdomain_as_slug',
            '*.shared-platform.net' => 'shared_tenant',
        ];

        $excludedSubdomains = ['www', 'api', 'admin', 'mail', 'ftp'];

        $this->resolver = new HybridDomainSubdomainResolver(
            $this->tenantRegistry,
            $domainMapping,
            $subdomainMapping,
            $excludedSubdomains
        );
    }

    public function testResolveTenantByExactDomainMapping(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('https://acme-client.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantBySubdomainPattern(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('tenant1');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('tenant1')
            ->willReturn($tenant);

        $request = Request::create('https://tenant1.myplatform.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantBySubdomainPatternWithFixedStrategy(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('shared_tenant');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('shared_tenant')
            ->willReturn($tenant);

        $request = Request::create('https://anything.shared-platform.net/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithExcludedSubdomain(): void
    {
        // Arrange
        $request = Request::create('https://www.myplatform.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithNestedSubdomain(): void
    {
        // Arrange
        $request = Request::create('https://sub.tenant1.myplatform.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithUnmatchedDomain(): void
    {
        // Arrange
        $request = Request::create('https://unknown-domain.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWhenTenantNotFoundInRegistry(): void
    {
        // Arrange
        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('nonexistent')
            ->willThrowException(new \Exception('Tenant not found'));

        $request = Request::create('https://nonexistent.myplatform.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithDomainAndPort(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('bio');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('bio')
            ->willReturn($tenant);

        $request = Request::create('https://bio-corp.org:8080/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithCaseInsensitiveDomain(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('https://ACME-CLIENT.COM/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testGetDomainMapping(): void
    {
        // Act
        $mapping = $this->resolver->getDomainMapping();

        // Assert
        $expected = [
            'acme-client.com' => 'acme',
            'acme-platform.net' => 'acme',
            'bio-corp.org' => 'bio',
        ];
        $this->assertSame($expected, $mapping);
    }

    public function testGetSubdomainMapping(): void
    {
        // Act
        $mapping = $this->resolver->getSubdomainMapping();

        // Assert
        $expected = [
            '*.myplatform.com' => 'use_subdomain_as_slug',
            '*.shared-platform.net' => 'shared_tenant',
        ];
        $this->assertSame($expected, $mapping);
    }

    public function testGetExcludedSubdomains(): void
    {
        // Act
        $excluded = $this->resolver->getExcludedSubdomains();

        // Assert
        $expected = ['www', 'api', 'admin', 'mail', 'ftp'];
        $this->assertSame($expected, $excluded);
    }

    public function testIsDomainMapped(): void
    {
        // Assert
        $this->assertTrue($this->resolver->isDomainMapped('acme-client.com'));
        $this->assertTrue($this->resolver->isDomainMapped('ACME-PLATFORM.NET')); // Case insensitive
        $this->assertTrue($this->resolver->isDomainMapped('bio-corp.org:8080')); // With port
        $this->assertFalse($this->resolver->isDomainMapped('unknown.com'));
    }

    public function testMatchesSubdomainPattern(): void
    {
        // Assert
        $this->assertTrue($this->resolver->matchesSubdomainPattern('tenant1.myplatform.com'));
        $this->assertTrue($this->resolver->matchesSubdomainPattern('anything.shared-platform.net'));
        $this->assertFalse($this->resolver->matchesSubdomainPattern('tenant1.unknown.com'));
        $this->assertFalse($this->resolver->matchesSubdomainPattern('myplatform.com')); // No subdomain
    }

    public function testResolveTenantPrioritizesDomainOverSubdomain(): void
    {
        // Arrange - Create a resolver where a domain could match both strategies
        $domainMapping = ['test.myplatform.com' => 'domain_tenant'];
        $subdomainMapping = ['*.myplatform.com' => 'use_subdomain_as_slug'];

        $resolver = new HybridDomainSubdomainResolver(
            $this->tenantRegistry,
            $domainMapping,
            $subdomainMapping
        );

        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('domain_tenant');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('domain_tenant') // Should use domain mapping, not subdomain
            ->willReturn($tenant);

        $request = Request::create('https://test.myplatform.com/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithEmptySubdomain(): void
    {
        // Arrange
        $request = Request::create('https://myplatform.com/path'); // No subdomain

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithDefaultExcludedSubdomains(): void
    {
        // Arrange - Create resolver with default excluded subdomains
        $resolver = new HybridDomainSubdomainResolver(
            $this->tenantRegistry,
            [],
            ['*.myplatform.com' => 'use_subdomain_as_slug']
        );

        $testCases = ['www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static'];

        foreach ($testCases as $subdomain) {
            $request = Request::create("https://{$subdomain}.myplatform.com/path");

            // Act
            $result = $resolver->resolveTenant($request);

            // Assert
            $this->assertNull($result, "Subdomain '{$subdomain}' should be excluded");
        }
    }
}
