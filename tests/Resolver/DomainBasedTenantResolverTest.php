<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\DomainBasedTenantResolver
 */
class DomainBasedTenantResolverTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private DomainBasedTenantResolver $resolver;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);

        $domainMapping = [
            'tenant-one.com' => 'tenant_one',
            'acme.org' => 'acme',
            'example.net' => 'example',
        ];

        $this->resolver = new DomainBasedTenantResolver(
            $this->tenantRegistry,
            $domainMapping
        );
    }

    public function testResolveTenantWithMappedDomain(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('tenant_one');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('tenant_one')
            ->willReturn($tenant);

        $request = Request::create('https://tenant-one.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithUnmappedDomain(): void
    {
        // Arrange
        $request = Request::create('https://unknown-domain.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithDomainAndPort(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('https://acme.org:8080/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithCaseInsensitiveDomain(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('example');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('example')
            ->willReturn($tenant);

        $request = Request::create('https://EXAMPLE.NET/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWhenTenantNotFoundInRegistry(): void
    {
        // Arrange
        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('tenant_one')
            ->willThrowException(new \Exception('Tenant not found'));

        $request = Request::create('https://tenant-one.com/path');

        // Act
        $result = $this->resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testGetDomainMapping(): void
    {
        // Act
        $mapping = $this->resolver->getDomainMapping();

        // Assert
        $expected = [
            'tenant-one.com' => 'tenant_one',
            'acme.org' => 'acme',
            'example.net' => 'example',
        ];
        $this->assertSame($expected, $mapping);
    }

    public function testIsDomainMapped(): void
    {
        // Assert
        $this->assertTrue($this->resolver->isDomainMapped('tenant-one.com'));
        $this->assertTrue($this->resolver->isDomainMapped('ACME.ORG')); // Case insensitive
        $this->assertTrue($this->resolver->isDomainMapped('example.net:8080')); // With port
        $this->assertFalse($this->resolver->isDomainMapped('unknown.com'));
    }

    public function testGetTenantSlugForDomain(): void
    {
        // Assert
        $this->assertSame('tenant_one', $this->resolver->getTenantSlugForDomain('tenant-one.com'));
        $this->assertSame('acme', $this->resolver->getTenantSlugForDomain('ACME.ORG')); // Case insensitive
        $this->assertSame('example', $this->resolver->getTenantSlugForDomain('example.net:8080')); // With port
        $this->assertNull($this->resolver->getTenantSlugForDomain('unknown.com'));
    }

    public function testResolveTenantWithEmptyDomainMapping(): void
    {
        // Arrange
        $resolver = new DomainBasedTenantResolver($this->tenantRegistry, []);
        $request = Request::create('https://any-domain.com/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testNormalizeHostWithVariousFormats(): void
    {
        // Test through public methods that use normalizeHost internally
        $testCases = [
            'example.com' => 'example',
            'EXAMPLE.COM' => 'example',
            'example.com:8080' => 'example',
            'example.com:443' => 'example',
            '  example.com  ' => 'example',
        ];

        $domainMapping = ['example.com' => 'example'];
        $resolver = new DomainBasedTenantResolver($this->tenantRegistry, $domainMapping);

        foreach ($testCases as $domain => $expectedSlug) {
            $this->assertSame($expectedSlug, $resolver->getTenantSlugForDomain($domain));
        }
    }
}
