<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\Resolver\DnsTxtTenantResolver
 */
class DnsTxtTenantResolverTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private DnsTxtTenantResolver $resolver;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->resolver = new DnsTxtTenantResolver(
            $this->tenantRegistry,
            5, // timeout
            true // enable cache
        );
    }

    public function testResolveTenantWithValidDnsTxtRecord(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('acme')
            ->willReturn($tenant);

        // Create a partial mock to override DNS query method
        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->method('queryDnsTxtRecord')
            ->with('acme.com')
            ->willReturn('acme');

        // Use reflection to set the tenant registry
        $reflection = new \ReflectionClass(DnsTxtTenantResolver::class);
        $registryProperty = $reflection->getProperty('tenantRegistry');
        $registryProperty->setAccessible(true);
        $registryProperty->setValue($resolver, $this->tenantRegistry);

        $request = Request::create('https://acme.com/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantWithNoDnsTxtRecord(): void
    {
        // Arrange
        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->method('queryDnsTxtRecord')
            ->with('unknown.com')
            ->willReturn(null);

        $request = Request::create('https://unknown.com/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithEmptyHost(): void
    {
        // Arrange
        $request = $this->createMock(Request::class);
        $request->method('getHost')->willReturn('');

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

        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->method('queryDnsTxtRecord')
            ->with('example.com')
            ->willReturn('nonexistent');

        // Use reflection to set the tenant registry
        $reflection = new \ReflectionClass(DnsTxtTenantResolver::class);
        $registryProperty = $reflection->getProperty('tenantRegistry');
        $registryProperty->setAccessible(true);
        $registryProperty->setValue($resolver, $this->tenantRegistry);

        $request = Request::create('https://example.com/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveTenantWithHostAndPort(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('bio');

        $this->tenantRegistry->expects($this->once())
            ->method('getBySlug')
            ->with('bio')
            ->willReturn($tenant);

        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->method('queryDnsTxtRecord')
            ->with('bio.org') // Port should be stripped
            ->willReturn('bio');

        // Use reflection to set the tenant registry
        $reflection = new \ReflectionClass(DnsTxtTenantResolver::class);
        $registryProperty = $reflection->getProperty('tenantRegistry');
        $registryProperty->setAccessible(true);
        $registryProperty->setValue($resolver, $this->tenantRegistry);

        $request = Request::create('https://bio.org:8080/path');

        // Act
        $result = $resolver->resolveTenant($request);

        // Assert
        $this->assertSame($tenant, $result);
    }

    public function testGetDnsQueryForHost(): void
    {
        // Act & Assert
        $this->assertSame('_tenant.acme.com', $this->resolver->getDnsQueryForHost('acme.com'));
        $this->assertSame('_tenant.bio.org', $this->resolver->getDnsQueryForHost('BIO.ORG')); // Case normalization
        $this->assertSame('_tenant.example.net', $this->resolver->getDnsQueryForHost('example.net:8080')); // Port removal
        $this->assertSame('_tenant.test.local', $this->resolver->getDnsQueryForHost('  test.local  ')); // Whitespace trimming
    }

    public function testGetDnsTimeout(): void
    {
        // Act & Assert
        $this->assertSame(5, $this->resolver->getDnsTimeout());
    }

    public function testIsCacheEnabled(): void
    {
        // Act & Assert
        $this->assertTrue($this->resolver->isCacheEnabled());
    }

    public function testConstructorWithCustomSettings(): void
    {
        // Arrange
        $resolver = new DnsTxtTenantResolver(
            $this->tenantRegistry,
            10, // custom timeout
            false // disable cache
        );

        // Act & Assert
        $this->assertSame(10, $resolver->getDnsTimeout());
        $this->assertFalse($resolver->isCacheEnabled());
    }

    public function testHasDnsTxtRecord(): void
    {
        // Arrange
        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->expects($this->exactly(2))
            ->method('queryDnsTxtRecord')
            ->willReturnMap([
                ['acme.com', 'acme'],
                ['unknown.com', null],
            ]);

        // Act & Assert
        $this->assertTrue($resolver->hasDnsTxtRecord('acme.com'));
        $this->assertFalse($resolver->hasDnsTxtRecord('unknown.com'));
    }

    public function testGetTenantIdentifierFromDns(): void
    {
        // Arrange
        /** @var DnsTxtTenantResolver&\PHPUnit\Framework\MockObject\MockObject $resolver */
        $resolver = $this->createPartialMock(DnsTxtTenantResolver::class, ['queryDnsTxtRecord']);
        $resolver->expects($this->exactly(2))
            ->method('queryDnsTxtRecord')
            ->willReturnMap([
                ['acme.com', 'acme'],
                ['unknown.com', null],
            ]);

        // Act & Assert
        $this->assertSame('acme', $resolver->getTenantIdentifierFromDns('acme.com'));
        $this->assertNull($resolver->getTenantIdentifierFromDns('unknown.com'));
    }

    /**
     * Test the sanitizeTenantIdentifier method through reflection.
     */
    public function testSanitizeTenantIdentifier(): void
    {
        // Arrange
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('sanitizeTenantIdentifier');
        $method->setAccessible(true);

        // Act & Assert
        $this->assertSame('acme', $method->invoke($this->resolver, 'acme'));
        $this->assertSame('acme-corp', $method->invoke($this->resolver, 'ACME-CORP')); // Case normalization
        $this->assertSame('bio_tech', $method->invoke($this->resolver, 'bio_tech'));
        $this->assertSame('tenant123', $method->invoke($this->resolver, 'tenant123'));
        $this->assertSame('test-tenant', $method->invoke($this->resolver, '  test-tenant  ')); // Whitespace trimming
        $this->assertSame('', $method->invoke($this->resolver, 'invalid@tenant')); // Invalid characters
        $this->assertSame('', $method->invoke($this->resolver, 'tenant.with.dots')); // Invalid characters
        $this->assertSame('', $method->invoke($this->resolver, '')); // Empty string
    }

    /**
     * Test the normalizeHost method through reflection.
     */
    public function testNormalizeHost(): void
    {
        // Arrange
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('normalizeHost');
        $method->setAccessible(true);

        // Act & Assert
        $this->assertSame('acme.com', $method->invoke($this->resolver, 'acme.com'));
        $this->assertSame('acme.com', $method->invoke($this->resolver, 'ACME.COM')); // Case normalization
        $this->assertSame('acme.com', $method->invoke($this->resolver, 'acme.com:8080')); // Port removal
        $this->assertSame('acme.com', $method->invoke($this->resolver, 'acme.com:443')); // HTTPS port removal
        $this->assertSame('acme.com', $method->invoke($this->resolver, '  acme.com  ')); // Whitespace trimming
    }

    /**
     * Test the extractTenantIdentifierFromRecords method through reflection.
     */
    public function testExtractTenantIdentifierFromRecords(): void
    {
        // Arrange
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('extractTenantIdentifierFromRecords');
        $method->setAccessible(true);

        // Act & Assert
        $this->assertSame('acme', $method->invoke($this->resolver, [['txt' => 'acme']]));
        $this->assertSame('bio-corp', $method->invoke($this->resolver, [['txt' => 'BIO-CORP']])); // Case normalization
        $this->assertNull($method->invoke($this->resolver, [])); // Empty records
        $this->assertNull($method->invoke($this->resolver, [['txt' => '']])); // Empty TXT value
        $this->assertNull($method->invoke($this->resolver, [['txt' => 'invalid@tenant']])); // Invalid characters
        $this->assertSame('valid-tenant', $method->invoke($this->resolver, [
            ['txt' => 'valid-tenant'],
            ['txt' => 'second-record'], // Should use first record
        ]));
    }

    /**
     * Test DNS query error handling.
     */
    public function testDnsQueryErrorHandling(): void
    {
        // This test verifies that DNS query errors are handled gracefully
        // In a real scenario, we would mock the DNS functions, but for unit testing
        // we test the error handling path through the public interface

        $request = Request::create('https://definitely-non-existent-domain-12345.invalid/path');
        $result = $this->resolver->resolveTenant($request);

        // Should return null when DNS query fails
        $this->assertNull($result);
    }

    /**
     * Test performance with multiple requests.
     */
    public function testPerformanceWithMultipleRequests(): void
    {
        $requests = [
            Request::create('https://test1.example.com/path'),
            Request::create('https://test2.example.com/path'),
            Request::create('https://test3.example.com/path'),
        ];

        $startTime = microtime(true);

        foreach ($requests as $request) {
            $this->resolver->resolveTenant($request);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should complete within reasonable time (adjust threshold as needed)
        $this->assertLessThan(5.0, $executionTime, 'DNS resolution should complete within 5 seconds');
    }

    /**
     * Test resolver with various host formats.
     */
    public function testResolverWithVariousHostFormats(): void
    {
        $testCases = [
            'simple.com',
            'subdomain.example.com',
            'multi.level.subdomain.example.com',
            'localhost',
            '127.0.0.1', // IP address
            'example.com:8080', // With port
            'UPPERCASE.COM', // Uppercase
        ];

        foreach ($testCases as $host) {
            $request = Request::create("https://{$host}/path");
            $result = $this->resolver->resolveTenant($request);

            // All should return null (no DNS records configured in test environment)
            // but should not throw exceptions
            $this->assertNull($result, "Host '{$host}' should not throw exception");
        }
    }
}
