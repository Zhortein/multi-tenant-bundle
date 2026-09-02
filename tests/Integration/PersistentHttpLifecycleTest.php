<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionMode;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

final class PersistentHttpLifecycleTest extends TenantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getTestData()->seedTenants([
            'tenant-a' => ['name' => 'Tenant A'],
            'tenant-b' => ['name' => 'Tenant B'],
        ]);
        $this->getTestData()->seedProducts('tenant-a', 1);
        $this->getTestData()->seedProducts('tenant-b', 1);
        self::assertNotNull($this->client);
        $this->client->disableReboot();
    }

    public function testAThenNoResolutionStartsAndEndsAtNoneOnTheSameKernel(): void
    {
        $this->request('tenant-a');
        $this->assertResponseContainsTenant('tenant-a');
        $this->assertNoneBoundary();

        $this->request();
        self::assertSame(400, $this->client?->getResponse()->getStatusCode());
        $this->assertNoneBoundary();
    }

    public function testAThenBThenNoResolutionNeverReusesAnOldIdentityMapOrFilter(): void
    {
        $this->request('tenant-a');
        $this->assertResponseContainsTenant('tenant-a');
        $this->assertNoneBoundary();

        $this->request('tenant-b');
        $this->assertResponseContainsTenant('tenant-b');
        $this->assertNoneBoundary();

        $this->request();
        self::assertSame(400, $this->client?->getResponse()->getStatusCode());
        $this->assertNoneBoundary();
    }

    public function testNormalRedirectAndControllerExceptionAllTerminateAtNone(): void
    {
        $this->expectOutputRegex('/controlled controller failure/');
        self::assertNotNull($this->client);
        $this->client->request('GET', '/test/lifecycle/context', ['tenant' => 'tenant-a']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNoneBoundary();

        $this->client->request('GET', '/test/lifecycle/redirect', ['tenant' => 'tenant-a']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertNoneBoundary();

        $this->client->request('GET', '/test/lifecycle/exception', ['tenant' => 'tenant-a']);
        self::assertSame(500, $this->client->getResponse()->getStatusCode());
        $this->assertNoneBoundary();
    }

    public function testSubRequestKeepsMainTenantAndExplicitGlobalThenUnresolvedEndAtNone(): void
    {
        self::assertNotNull($this->client);
        $this->client->request('GET', '/test/lifecycle/sub-request', ['tenant' => 'tenant-a']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('tenant-a', $payload['before'] ?? null);
        self::assertSame('{"tenant":"tenant-a"}', $payload['sub_request'] ?? null);
        self::assertSame('tenant-a', $payload['after'] ?? null);
        $this->assertNoneBoundary();

        $this->client->request('GET', '/test/lifecycle/global');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('"global":true', (string) $this->client->getResponse()->getContent());
        $this->assertNoneBoundary();

        $this->request();
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertNoneBoundary();
    }

    public function testStreamKeepsTenantUntilContentIsSentAndTerminateThenResets(): void
    {
        self::assertNotNull($this->client);
        $kernel = $this->client->getKernel();
        $request = Request::create('/test/lifecycle/stream?tenant=tenant-a');
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame('tenant-a', $this->getTenantContext()->getTenant()?->getSlug());
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        self::assertSame('tenant-a', $content);
        self::assertSame('tenant-a', $this->getTenantContext()->getTenant()?->getSlug());

        $kernel->terminate($request, $response);
        $this->assertNoneBoundary();
    }

    private function request(?string $tenant = null): void
    {
        self::assertNotNull($this->client);
        $parameters = null === $tenant ? [] : ['tenant' => $tenant];
        $this->client->request('GET', '/test/products', $parameters);
    }

    private function assertResponseContainsTenant(string $tenant): void
    {
        self::assertNotNull($this->client);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString($tenant, $content);
    }

    private function assertNoneBoundary(): void
    {
        self::assertNull($this->getTenantContext()->getTenant());
        $synchronizer = static::getContainer()->get(TenantContextSynchronizerInterface::class);
        self::assertInstanceOf(TenantContextSynchronizerInterface::class, $synchronizer);
        self::assertSame(TenantConnectionMode::NONE, $synchronizer->currentState()->mode);

        $manager = $this->getEntityManager();
        self::assertSame(0, $manager->getUnitOfWork()->size());
        $filter = $manager->getFilters()->getFilter('tenant_filter');
        self::assertSame("'none'", $filter->getParameter('tenant_context_mode'));
        self::assertSame("'__NO_TENANT__'", $filter->getParameter('tenant_id'));
    }
}
