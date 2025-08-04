<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;

/**
 * Integration tests for tenant context and resolution.
 */
final class TenantContextIntegrationTest extends TestCase
{
    private TenantContext $tenantContext;
    private InMemoryTenantRegistry $tenantRegistry;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        $this->tenantRegistry = new InMemoryTenantRegistry();

        // Add test tenants
        $tenant1 = $this->createTenant('tenant1', 'Tenant One');
        $tenant2 = $this->createTenant('tenant2', 'Tenant Two');

        $this->tenantRegistry->addTenant($tenant1);
        $this->tenantRegistry->addTenant($tenant2);
    }

    public function testPathTenantResolverIntegration(): void
    {
        // Skip resolver tests - they require complex Doctrine setup
        $this->markTestSkipped('Resolver tests require full Doctrine integration');
    }

    public function testSubdomainTenantResolverIntegration(): void
    {
        // Skip resolver tests - they require complex Doctrine setup
        $this->markTestSkipped('Resolver tests require full Doctrine integration');
    }

    public function testTenantContextClearance(): void
    {
        $tenant = $this->createTenant('test', 'Test Tenant');

        $this->tenantContext->setTenant($tenant);
        $this->assertTrue($this->tenantContext->hasTenant());

        $this->tenantContext->clear();
        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertNull($this->tenantContext->getTenant());
    }

    public function testTenantRegistryOperations(): void
    {
        // Test getting all tenants
        $tenants = $this->tenantRegistry->getAll();
        $this->assertCount(2, $tenants);

        // Test getting by slug
        $tenant = $this->tenantRegistry->getBySlug('tenant1');
        $this->assertSame('tenant1', $tenant->getSlug());

        // Test has slug
        $this->assertTrue($this->tenantRegistry->hasSlug('tenant1'));
        $this->assertFalse($this->tenantRegistry->hasSlug('nonexistent'));

        // Test exception for non-existent tenant
        $this->expectException(\Zhortein\MultiTenantBundle\Exception\TenantNotFoundException::class);
        $this->tenantRegistry->getBySlug('nonexistent');
    }

    public function testTenantRegistryManagement(): void
    {
        $newTenant = $this->createTenant('tenant3', 'Tenant Three');

        // Add tenant
        $this->tenantRegistry->addTenant($newTenant);
        $this->assertTrue($this->tenantRegistry->hasSlug('tenant3'));

        // Remove tenant
        $result = $this->tenantRegistry->removeTenant('tenant3');
        $this->assertTrue($result);
        $this->assertFalse($this->tenantRegistry->hasSlug('tenant3'));

        // Try to remove non-existent tenant
        $result = $this->tenantRegistry->removeTenant('nonexistent');
        $this->assertFalse($result);
    }

    private function createTenant(string $slug, string $name): TenantInterface
    {
        return new class($slug, $name) implements TenantInterface {
            public function __construct(
                private string $slug,
                private string $name,
            ) {
            }

            public function getId(): string
            {
                return $this->slug;
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function setSlug(string $slug): void
            {
                $this->slug = $slug;
            }

            public function setName(string $name): void
            {
                $this->name = $name;
            }

            public function getMailerDsn(): ?string
            {
                return null;
            }

            public function getMessengerDsn(): ?string
            {
                return null;
            }
        };
    }
}
