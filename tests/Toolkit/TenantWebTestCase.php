<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Toolkit;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Base test case for HTTP/web tests with tenant context support.
 *
 * This class extends WebTestCase and provides utilities for:
 * - Creating clients with tenant-aware server parameters
 * - Managing tenant context during HTTP requests
 * - Testing different tenant resolution strategies
 */
abstract class TenantWebTestCase extends WebTestCase
{
    use WithTenantTrait;

    protected ?KernelBrowser $client = null;
    protected ?EntityManagerInterface $entityManager = null;
    protected ?TenantContextInterface $tenantContext = null;
    protected ?TenantRegistryInterface $tenantRegistry = null;
    protected ?TestData $testData = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->tenantRegistry = $container->get(TenantRegistryInterface::class);
        $this->testData = new TestData($this->entityManager, $this->tenantRegistry);
    }

    protected function tearDown(): void
    {
        $this->testData?->clearAll();
        $this->tenantContext?->clear();

        parent::tearDown();
    }

    /**
     * Create a client configured for subdomain-based tenant resolution.
     *
     * @param string $tenantSlug The tenant slug to use as subdomain
     * @param string $baseDomain The base domain (default: 'lvh.me')
     * @param array  $options    Additional client options
     * @param array  $server     Additional server parameters
     *
     * @return KernelBrowser The configured client
     */
    protected function createSubdomainClient(
        string $tenantSlug,
        string $baseDomain = 'lvh.me',
        array $options = [],
        array $server = [],
    ): KernelBrowser {
        $server['HTTP_HOST'] = sprintf('%s.%s', $tenantSlug, $baseDomain);

        return static::createClient($options, $server);
    }

    /**
     * Create a client configured for header-based tenant resolution.
     *
     * @param string $tenantSlug The tenant slug
     * @param string $headerName The header name (default: 'X-Tenant-ID')
     * @param array  $options    Additional client options
     * @param array  $server     Additional server parameters
     *
     * @return KernelBrowser The configured client
     */
    protected function createHeaderClient(
        string $tenantSlug,
        string $headerName = 'X-Tenant-ID',
        array $options = [],
        array $server = [],
    ): KernelBrowser {
        $server['HTTP_'.str_replace('-', '_', strtoupper($headerName))] = $tenantSlug;

        return static::createClient($options, $server);
    }

    /**
     * Create a client configured for path-based tenant resolution.
     *
     * @param array $options Additional client options
     * @param array $server  Additional server parameters
     *
     * @return KernelBrowser The configured client
     */
    protected function createPathClient(array $options = [], array $server = []): KernelBrowser
    {
        return static::createClient($options, $server);
    }

    /**
     * Create a client configured for query parameter-based tenant resolution.
     *
     * @param array $options Additional client options
     * @param array $server  Additional server parameters
     *
     * @return KernelBrowser The configured client
     */
    protected function createQueryClient(array $options = [], array $server = []): KernelBrowser
    {
        return static::createClient($options, $server);
    }

    /**
     * Create a client configured for domain-based tenant resolution.
     *
     * @param string $domain  The full domain name
     * @param array  $options Additional client options
     * @param array  $server  Additional server parameters
     *
     * @return KernelBrowser The configured client
     */
    protected function createDomainClient(
        string $domain,
        array $options = [],
        array $server = [],
    ): KernelBrowser {
        $server['HTTP_HOST'] = $domain;

        return static::createClient($options, $server);
    }

    /**
     * Make a request with tenant context in the path.
     *
     * @param KernelBrowser $client        The client to use
     * @param string        $method        HTTP method
     * @param string        $tenantSlug    The tenant slug
     * @param string        $uri           The URI (without tenant prefix)
     * @param array         $parameters    Request parameters
     * @param array         $files         Files to upload
     * @param array         $server        Server parameters
     * @param string|null   $content       Request content
     * @param bool          $changeHistory Whether to change history
     */
    protected function requestWithTenantPath(
        KernelBrowser $client,
        string $method,
        string $tenantSlug,
        string $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
        bool $changeHistory = true,
    ): \Symfony\Component\DomCrawler\Crawler {
        $tenantUri = sprintf('/%s%s', $tenantSlug, $uri);

        return $client->request($method, $tenantUri, $parameters, $files, $server, $content, $changeHistory);
    }

    /**
     * Make a request with tenant context in query parameters.
     *
     * @param KernelBrowser $client        The client to use
     * @param string        $method        HTTP method
     * @param string        $tenantSlug    The tenant slug
     * @param string        $uri           The URI
     * @param string        $paramName     The query parameter name (default: 'tenant')
     * @param array         $parameters    Additional request parameters
     * @param array         $files         Files to upload
     * @param array         $server        Server parameters
     * @param string|null   $content       Request content
     * @param bool          $changeHistory Whether to change history
     */
    protected function requestWithTenantQuery(
        KernelBrowser $client,
        string $method,
        string $tenantSlug,
        string $uri,
        string $paramName = 'tenant',
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
        bool $changeHistory = true,
    ): \Symfony\Component\DomCrawler\Crawler {
        $parameters[$paramName] = $tenantSlug;

        return $client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
    }

    /**
     * Assert that the response contains data for a specific tenant only.
     *
     * @param string $tenantSlug The expected tenant slug
     * @param string $content    The response content to check
     */
    protected function assertResponseContainsTenantData(string $tenantSlug, string $content): void
    {
        $this->assertStringContainsString($tenantSlug, $content, 'Response should contain tenant-specific data');
    }

    /**
     * Assert that the response does not contain data from other tenants.
     *
     * @param array  $otherTenantSlugs Array of other tenant slugs that should not appear
     * @param string $content          The response content to check
     */
    protected function assertResponseDoesNotContainOtherTenantData(array $otherTenantSlugs, string $content): void
    {
        foreach ($otherTenantSlugs as $otherSlug) {
            $this->assertStringNotContainsString(
                $otherSlug,
                $content,
                sprintf('Response should not contain data from tenant "%s"', $otherSlug)
            );
        }
    }

    protected function getTenantContext(): TenantContextInterface
    {
        if (!$this->tenantContext) {
            throw new \RuntimeException('TenantContext not initialized. Call setUp() first.');
        }

        return $this->tenantContext;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        if (!$this->entityManager) {
            throw new \RuntimeException('EntityManager not initialized. Call setUp() first.');
        }

        return $this->entityManager;
    }

    protected function getTenantRegistry(): TenantRegistryInterface
    {
        if (!$this->tenantRegistry) {
            throw new \RuntimeException('TenantRegistry not initialized. Call setUp() first.');
        }

        return $this->tenantRegistry;
    }

    protected function getTestData(): TestData
    {
        if (!$this->testData) {
            throw new \RuntimeException('TestData not initialized. Call setUp() first.');
        }

        return $this->testData;
    }
}
