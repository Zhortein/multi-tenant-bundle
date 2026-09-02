<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

final class PublicTenantWebTest extends TenantWebTestCase
{
    public function testExplicitLateResolutionAndDisabledAutomaticResolutionRemainIsolatedWithoutReboot(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->assertTenantResponse($client, '/_test/tenant-context/load', 'tenant-a', 'tenant-a');
        $this->assertTenantResponse($client, '/_test/tenant-context', 'tenant-a', 'none');
        $this->assertTenantResponse($client, '/_test/tenant-context/load', 'tenant-b', 'tenant-b');
        $this->assertTenantResponse($client, '/_test/tenant-context', null, 'none');
        self::assertNull($this->tenantContext()->getTenant());

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        self::assertNull($this->tenantContext()->getTenant());
        $this->assertTenantResponse($client, '/_test/tenant-context/load', 'tenant-b', 'tenant-b');
    }

    public function testResolverExceptionLeavesThePersistentKernelAtNone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);

        $this->assertTenantResponse($client, '/_test/tenant-context/load', 'tenant-a', 'tenant-a');

        try {
            $client->request('GET', '/_test/tenant-context/load', server: ['HTTP_X_CONSUMER_TENANT' => 'throw']);
            self::fail('The resolver exception must remain observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Expected consumer resolver failure.', $exception->getMessage());
        }

        self::assertNull($this->tenantContext()->getTenant());
        $this->assertTenantResponse($client, '/_test/tenant-context', null, 'none');
    }

    private function assertTenantResponse(KernelBrowser $client, string $path, ?string $tenant, string $expected): void
    {
        $server = null === $tenant ? [] : ['HTTP_X_CONSUMER_TENANT' => $tenant];
        $client->request('GET', $path, server: $server);

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $client->getResponse()->getContent());
        self::assertNull($this->tenantContext()->getTenant());
    }
}
