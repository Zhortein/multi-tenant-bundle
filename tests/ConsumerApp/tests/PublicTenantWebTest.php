<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

final class PublicTenantWebTest extends TenantWebTestCase
{
    public function testFunctionalRequestsRemainIsolatedAcrossTenantScopesAndReboot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenantA = new Tenant('tenant-a', 'tenant-a');
        $tenantB = new Tenant('tenant-b', 'tenant-b');

        $this->assertTenantResponse($client, $tenantA, 'tenant-a');
        $this->assertTenantResponse($client, $tenantB, 'tenant-b');
        $this->assertTenantResponse($client, $tenantA, 'tenant-a');
        self::assertNull($this->tenantContext()->getTenant());

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        self::assertNull($this->tenantContext()->getTenant());
        $this->assertTenantResponse($client, $tenantB, 'tenant-b');
    }

    private function assertTenantResponse(KernelBrowser $client, Tenant $tenant, string $expected): void
    {
        $this->withTenant($tenant, static function () use ($client): void {
            $client->request('GET', '/_test/tenant-context');
        });

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $client->getResponse()->getContent());
    }
}
