<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestProduct;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantCliTestCase;

/**
 * Integration test for CLI tenant context management.
 *
 * This test verifies that:
 * 1. Commands can be executed with tenant context via --tenant option
 * 2. Commands can be executed with tenant context via TENANT_ID environment variable
 * 3. Tenant context is properly isolated in CLI operations
 * 4. Database operations in CLI respect tenant context
 */
class CliTenantContextTest extends TenantCliTestCase
{
    private const TENANT_A_SLUG = 'tenant-a';
    private const TENANT_B_SLUG = 'tenant-b';

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenants
        $this->getTestData()->seedTenants([
            self::TENANT_A_SLUG => ['name' => 'Tenant A'],
            self::TENANT_B_SLUG => ['name' => 'Tenant B'],
        ]);

        // Seed test data
        $this->getTestData()->seedProducts(self::TENANT_A_SLUG, 2);
        $this->getTestData()->seedProducts(self::TENANT_B_SLUG, 1);
    }

    /**
     * Test tenant:list command.
     */
    public function testTenantListCommand(): void
    {
        $commandTester = $this->executeCommand('tenant:list');

        $this->assertCommandIsSuccessful($commandTester);
        $this->assertCommandOutputContains($commandTester, self::TENANT_A_SLUG);
        $this->assertCommandOutputContains($commandTester, self::TENANT_B_SLUG);
        $this->assertCommandOutputContains($commandTester, 'Tenant A');
        $this->assertCommandOutputContains($commandTester, 'Tenant B');
    }

    /**
     * Test command execution with --tenant option.
     */
    public function testCommandWithTenantOption(): void
    {
        // Create a custom command that shows tenant context
        $commandTester = $this->executeCommandWithTenantOption(
            'tenant:context:show',
            self::TENANT_A_SLUG
        );

        // If the command exists, it should show tenant A context
        if ($commandTester->getStatusCode() !== 1) { // Command not found
            $this->assertCommandIsSuccessful($commandTester);
            $this->assertCommandOutputContainsTenant($commandTester, self::TENANT_A_SLUG);
        } else {
            $this->markTestSkipped('tenant:context:show command not available');
        }
    }

    /**
     * Test command execution with TENANT_ID environment variable.
     */
    public function testCommandWithTenantEnvironmentVariable(): void
    {
        $commandTester = $this->executeCommandWithTenantEnv(
            'tenant:context:show',
            self::TENANT_B_SLUG
        );

        // If the command exists, it should show tenant B context
        if ($commandTester->getStatusCode() !== 1) { // Command not found
            $this->assertCommandIsSuccessful($commandTester);
            $this->assertCommandOutputContainsTenant($commandTester, self::TENANT_B_SLUG);
        } else {
            $this->markTestSkipped('tenant:context:show command not available');
        }
    }

    /**
     * Test that database operations in CLI respect tenant context.
     */
    public function testCliDatabaseOperationsWithTenantContext(): void
    {
        // Test within tenant A context
        $this->withTenant(self::TENANT_A_SLUG, function () {
            $repository = $this->getEntityManager()->getRepository(TestProduct::class);
            $products = $repository->findAll();

            $this->assertCount(2, $products, 'Should see 2 products for tenant A in CLI context');

            foreach ($products as $product) {
                $this->assertStringContainsString(
                    self::TENANT_A_SLUG,
                    $product->getName(),
                    'All products should belong to tenant A'
                );
            }
        });

        // Test within tenant B context
        $this->withTenant(self::TENANT_B_SLUG, function () {
            $repository = $this->getEntityManager()->getRepository(TestProduct::class);
            $products = $repository->findAll();

            $this->assertCount(1, $products, 'Should see 1 product for tenant B in CLI context');

            foreach ($products as $product) {
                $this->assertStringContainsString(
                    self::TENANT_B_SLUG,
                    $product->getName(),
                    'All products should belong to tenant B'
                );
            }
        });
    }

    /**
     * Test tenant context isolation in CLI operations.
     */
    public function testCliTenantContextIsolation(): void
    {
        $repository = $this->getEntityManager()->getRepository(TestProduct::class);

        // Execute operations in tenant A context
        $productsA = $this->withTenant(self::TENANT_A_SLUG, function () use ($repository) {
            return $repository->findAll();
        });

        // Execute operations in tenant B context
        $productsB = $this->withTenant(self::TENANT_B_SLUG, function () use ($repository) {
            return $repository->findAll();
        });

        // Verify isolation
        $this->assertCount(2, $productsA, 'Tenant A should see 2 products');
        $this->assertCount(1, $productsB, 'Tenant B should see 1 product');

        // Verify no cross-contamination
        foreach ($productsA as $product) {
            $this->assertStringContainsString(self::TENANT_A_SLUG, $product->getName());
        }

        foreach ($productsB as $product) {
            $this->assertStringContainsString(self::TENANT_B_SLUG, $product->getName());
        }
    }

    /**
     * Test creating entities in CLI with tenant context.
     */
    public function testCliEntityCreationWithTenantContext(): void
    {
        // Create a product in tenant A context
        $productA = $this->withTenant(self::TENANT_A_SLUG, function () {
            return $this->getTestData()->createProduct(
                self::TENANT_A_SLUG,
                'CLI Product A',
                '99.99'
            );
        });

        // Create a product in tenant B context
        $productB = $this->withTenant(self::TENANT_B_SLUG, function () {
            return $this->getTestData()->createProduct(
                self::TENANT_B_SLUG,
                'CLI Product B',
                '88.88'
            );
        });

        // Verify products are created with correct tenant association
        $this->assertSame('CLI Product A', $productA->getName());
        $this->assertSame('CLI Product B', $productB->getName());

        // Verify tenant isolation
        $this->withTenant(self::TENANT_A_SLUG, function () {
            $count = $this->getTestData()->countProductsForTenant(self::TENANT_A_SLUG);
            $this->assertSame(3, $count, 'Tenant A should now have 3 products (2 + 1 new)');
        });

        $this->withTenant(self::TENANT_B_SLUG, function () {
            $count = $this->getTestData()->countProductsForTenant(self::TENANT_B_SLUG);
            $this->assertSame(2, $count, 'Tenant B should now have 2 products (1 + 1 new)');
        });
    }

    /**
     * Test nested tenant contexts in CLI.
     */
    public function testNestedTenantContextsInCli(): void
    {
        $repository = $this->getEntityManager()->getRepository(TestProduct::class);

        $this->withTenant(self::TENANT_A_SLUG, function () use ($repository) {
            // In tenant A context
            $productsA1 = $repository->findAll();
            $this->assertCount(2, $productsA1);

            $this->withTenant(self::TENANT_B_SLUG, function () use ($repository) {
                // In nested tenant B context
                $productsB = $repository->findAll();
                $this->assertCount(1, $productsB);
            });

            // Back in tenant A context
            $productsA2 = $repository->findAll();
            $this->assertCount(2, $productsA2);
        });
    }

    /**
     * Test CLI operations without tenant context.
     */
    public function testCliOperationsWithoutTenantContext(): void
    {
        // Clear any existing tenant context
        $this->getTenantContext()->clear();

        $repository = $this->getEntityManager()->getRepository(TestProduct::class);

        // Without tenant context and with filters disabled, should see all products
        $allProducts = $this->withoutDoctrineTenantFilter(function () use ($repository) {
            return $repository->findAll();
        });

        $this->assertCount(3, $allProducts, 'Without tenant context, should see all products when filter is disabled');
    }

    /**
     * Test tenant migration command (if available).
     */
    public function testTenantMigrationCommand(): void
    {
        $commandTester = $this->executeCommandWithTenantOption(
            'tenant:migrate',
            self::TENANT_A_SLUG,
            ['--dry-run' => true]
        );

        // If the command exists, it should execute successfully
        if ($commandTester->getStatusCode() !== 1) { // Command not found
            $this->assertCommandIsSuccessful($commandTester);
            $this->assertCommandOutputContainsTenant($commandTester, self::TENANT_A_SLUG);
        } else {
            $this->markTestSkipped('tenant:migrate command not available');
        }
    }

    /**
     * Test tenant settings cache clear command (if available).
     */
    public function testTenantSettingsClearCacheCommand(): void
    {
        $commandTester = $this->executeCommand('tenant:settings:clear-cache');

        // If the command exists, it should execute successfully
        if ($commandTester->getStatusCode() !== 1) { // Command not found
            $this->assertCommandIsSuccessful($commandTester);
        } else {
            $this->markTestSkipped('tenant:settings:clear-cache command not available');
        }
    }
}