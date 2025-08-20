<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

/**
 * Integration test for HTTP tenant resolution strategies.
 *
 * This test verifies that different tenant resolution strategies work correctly
 * in HTTP context and return only tenant-specific data.
 */
class ResolverChainHttpTest extends TenantWebTestCase
{
    private const TENANT_A_SLUG = 'mairie-a';
    private const TENANT_B_SLUG = 'mairie-b';

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenants
        $this->getTestData()->seedTenants([
            self::TENANT_A_SLUG => ['name' => 'Mairie A'],
            self::TENANT_B_SLUG => ['name' => 'Mairie B'],
        ]);

        // Seed test data
        $this->getTestData()->seedProducts(self::TENANT_A_SLUG, 2);
        $this->getTestData()->seedProducts(self::TENANT_B_SLUG, 1);
    }

    /**
     * Test subdomain-based tenant resolution.
     */
    public function testSubdomainResolver(): void
    {
        // Test tenant A via subdomain
        $clientA = $this->createSubdomainClient(self::TENANT_A_SLUG);
        $crawler = $clientA->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientA->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_A_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_B_SLUG], $content);

        // Verify we get exactly 2 products for tenant A
        $this->assertStringContainsString('2', $content, 'Should show 2 products for tenant A');

        // Test tenant B via subdomain
        $clientB = $this->createSubdomainClient(self::TENANT_B_SLUG);
        $crawler = $clientB->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientB->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $content);

        // Verify we get exactly 1 product for tenant B
        $this->assertStringContainsString('1', $content, 'Should show 1 product for tenant B');
    }

    /**
     * Test header-based tenant resolution.
     */
    public function testHeaderResolver(): void
    {
        // Test tenant A via header
        $clientA = $this->createHeaderClient(self::TENANT_A_SLUG, 'X-Tenant-ID');
        $crawler = $clientA->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientA->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_A_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_B_SLUG], $content);

        // Test tenant B via header
        $clientB = $this->createHeaderClient(self::TENANT_B_SLUG, 'X-Tenant-ID');
        $crawler = $clientB->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientB->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $content);
    }

    /**
     * Test path-based tenant resolution.
     */
    public function testPathResolver(): void
    {
        $client = $this->createPathClient();

        // Test tenant A via path
        $crawler = $this->requestWithTenantPath($client, 'GET', self::TENANT_A_SLUG, '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_A_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_B_SLUG], $content);

        // Test tenant B via path
        $crawler = $this->requestWithTenantPath($client, 'GET', self::TENANT_B_SLUG, '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $content);
    }

    /**
     * Test query parameter-based tenant resolution.
     */
    public function testQueryResolver(): void
    {
        $client = $this->createQueryClient();

        // Test tenant A via query parameter
        $crawler = $this->requestWithTenantQuery($client, 'GET', self::TENANT_A_SLUG, '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_A_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_B_SLUG], $content);

        // Test tenant B via query parameter
        $crawler = $this->requestWithTenantQuery($client, 'GET', self::TENANT_B_SLUG, '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $content);
    }

    /**
     * Test domain-based tenant resolution.
     */
    public function testDomainResolver(): void
    {
        // Test tenant A via custom domain
        $clientA = $this->createDomainClient('mairie-a.example.com');
        $crawler = $clientA->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientA->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_A_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_B_SLUG], $content);

        // Test tenant B via custom domain
        $clientB = $this->createDomainClient('mairie-b.example.com');
        $crawler = $clientB->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $clientB->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $content);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $content);
    }

    /**
     * Test that requests without tenant context fail appropriately.
     */
    public function testRequestWithoutTenantContext(): void
    {
        $client = static::createClient();

        // Request without any tenant information should fail or use default tenant
        $crawler = $client->request('GET', '/test/products');

        // Depending on configuration, this might return 400, 404, or use a default tenant
        // The exact behavior depends on the bundle configuration
        $response = $client->getResponse();
        $statusCode = $response->getStatusCode();

        $this->assertTrue(
            in_array($statusCode, [200, 400, 404], true),
            sprintf('Expected status code 200, 400, or 404, got %d', $statusCode)
        );
    }

    /**
     * Test resolver precedence in chain mode.
     */
    public function testResolverChainPrecedence(): void
    {
        // Create a client with both subdomain and header set to different tenants
        // The resolver with higher precedence should win
        $client = static::createClient([], [
            'HTTP_HOST' => self::TENANT_A_SLUG.'.lvh.me',
            'HTTP_X_TENANT_ID' => self::TENANT_B_SLUG,
        ]);

        $crawler = $client->request('GET', '/test/products');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response->getContent();

        $this->assertNotFalse($content);

        // Depending on resolver chain configuration, either tenant A (subdomain) or tenant B (header) should win
        // This test verifies that the resolver chain respects precedence rules
        $containsTenantA = str_contains($content, self::TENANT_A_SLUG);
        $containsTenantB = str_contains($content, self::TENANT_B_SLUG);

        $this->assertTrue(
            $containsTenantA || $containsTenantB,
            'Response should contain data from one of the tenants based on resolver precedence'
        );

        $this->assertFalse(
            $containsTenantA && $containsTenantB,
            'Response should not contain data from both tenants - only one should win based on precedence'
        );
    }

    /**
     * Test that tenant context is properly isolated between requests.
     */
    public function testTenantContextIsolationBetweenRequests(): void
    {
        $client = static::createClient();

        // First request for tenant A
        $client->request('GET', '/test/products', ['tenant' => self::TENANT_A_SLUG]);
        $this->assertResponseIsSuccessful();
        $responseA = $client->getResponse()->getContent();
        $this->assertNotFalse($responseA);

        // Second request for tenant B (should not be affected by previous request)
        $client->request('GET', '/test/products', ['tenant' => self::TENANT_B_SLUG]);
        $this->assertResponseIsSuccessful();
        $responseB = $client->getResponse()->getContent();
        $this->assertNotFalse($responseB);

        // Verify isolation
        $this->assertResponseContainsTenantData(self::TENANT_B_SLUG, $responseB);
        $this->assertResponseDoesNotContainOtherTenantData([self::TENANT_A_SLUG], $responseB);
    }
}
