<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestProduct;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantWebTestCase;

/**
 * Integration test for PostgreSQL Row-Level Security (RLS) isolation.
 *
 * This test verifies that:
 * 1. Doctrine filters work correctly for tenant isolation
 * 2. PostgreSQL RLS provides defense-in-depth even when Doctrine filters are disabled
 * 3. Tenant context properly sets PostgreSQL session variables
 */
class RlsIsolationTest extends TenantWebTestCase
{
    private const TENANT_A_SLUG = 'tenant-a';
    private const TENANT_B_SLUG = 'tenant-b';
    private const TENANT_A_PRODUCTS = 2;
    private const TENANT_B_PRODUCTS = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenants
        $this->getTestData()->seedTenants([
            self::TENANT_A_SLUG => ['name' => 'Tenant A'],
            self::TENANT_B_SLUG => ['name' => 'Tenant B'],
        ]);

        // Seed test data
        $this->getTestData()->seedProducts(self::TENANT_A_SLUG, self::TENANT_A_PRODUCTS);
        $this->getTestData()->seedProducts(self::TENANT_B_SLUG, self::TENANT_B_PRODUCTS);
    }

    /**
     * Test Case #1: Doctrine filter ON - should see only tenant-specific data.
     */
    public function testDoctrineFilterIsolation(): void
    {
        $productRepository = $this->getEntityManager()->getRepository(TestProduct::class);

        // Test tenant A context
        $this->withTenant(self::TENANT_A_SLUG, function () use ($productRepository) {
            $products = $productRepository->findAll();
            $this->assertCount(
                self::TENANT_A_PRODUCTS,
                $products,
                'Tenant A should see exactly ' . self::TENANT_A_PRODUCTS . ' products'
            );

            foreach ($products as $product) {
                $this->assertStringContainsString(
                    self::TENANT_A_SLUG,
                    $product->getName(),
                    'All products should belong to tenant A'
                );
            }
        });

        // Test tenant B context
        $this->withTenant(self::TENANT_B_SLUG, function () use ($productRepository) {
            $products = $productRepository->findAll();
            $this->assertCount(
                self::TENANT_B_PRODUCTS,
                $products,
                'Tenant B should see exactly ' . self::TENANT_B_PRODUCTS . ' product'
            );

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
     * Test Case #2: Doctrine filter OFF + RLS ON - PostgreSQL RLS should still provide isolation.
     *
     * This is the critical test that proves RLS works as defense-in-depth.
     * Even with Doctrine filters disabled, PostgreSQL should only return tenant-specific data.
     */
    public function testRlsIsolationWithDoctrineFilterDisabled(): void
    {
        $productRepository = $this->getEntityManager()->getRepository(TestProduct::class);

        // Test tenant A context with Doctrine filter disabled
        $this->withTenant(self::TENANT_A_SLUG, function () use ($productRepository) {
            $this->withoutDoctrineTenantFilter(function () use ($productRepository) {
                // Even with Doctrine filter disabled, RLS should limit results to tenant A
                $products = $productRepository->findAll();

                $this->assertCount(
                    self::TENANT_A_PRODUCTS,
                    $products,
                    'RLS should ensure tenant A sees only ' . self::TENANT_A_PRODUCTS . ' products (not all ' . (self::TENANT_A_PRODUCTS + self::TENANT_B_PRODUCTS) . ')'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_A_SLUG,
                        $product->getName(),
                        'RLS should ensure all products belong to tenant A'
                    );
                }
            });
        });

        // Test tenant B context with Doctrine filter disabled
        $this->withTenant(self::TENANT_B_SLUG, function () use ($productRepository) {
            $this->withoutDoctrineTenantFilter(function () use ($productRepository) {
                // Even with Doctrine filter disabled, RLS should limit results to tenant B
                $products = $productRepository->findAll();

                $this->assertCount(
                    self::TENANT_B_PRODUCTS,
                    $products,
                    'RLS should ensure tenant B sees only ' . self::TENANT_B_PRODUCTS . ' product (not all ' . (self::TENANT_A_PRODUCTS + self::TENANT_B_PRODUCTS) . ')'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_B_SLUG,
                        $product->getName(),
                        'RLS should ensure all products belong to tenant B'
                    );
                }
            });
        });
    }

    /**
     * Test that raw DQL queries also respect RLS isolation.
     */
    public function testRlsIsolationWithDqlQueries(): void
    {
        $entityManager = $this->getEntityManager();

        // Test tenant A context
        $this->withTenant(self::TENANT_A_SLUG, function () use ($entityManager) {
            $this->withoutDoctrineTenantFilter(function () use ($entityManager) {
                $query = $entityManager->createQuery(
                    'SELECT p FROM ' . TestProduct::class . ' p ORDER BY p.id'
                );

                $products = $query->getResult();

                $this->assertCount(
                    self::TENANT_A_PRODUCTS,
                    $products,
                    'RLS should limit DQL query results to tenant A products only'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_A_SLUG,
                        $product->getName(),
                        'All DQL query results should belong to tenant A'
                    );
                }
            });
        });

        // Test tenant B context
        $this->withTenant(self::TENANT_B_SLUG, function () use ($entityManager) {
            $this->withoutDoctrineTenantFilter(function () use ($entityManager) {
                $query = $entityManager->createQuery(
                    'SELECT p FROM ' . TestProduct::class . ' p ORDER BY p.id'
                );

                $products = $query->getResult();

                $this->assertCount(
                    self::TENANT_B_PRODUCTS,
                    $products,
                    'RLS should limit DQL query results to tenant B products only'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_B_SLUG,
                        $product->getName(),
                        'All DQL query results should belong to tenant B'
                    );
                }
            });
        });
    }

    /**
     * Test that native SQL queries also respect RLS isolation.
     */
    public function testRlsIsolationWithNativeSqlQueries(): void
    {
        $connection = $this->getEntityManager()->getConnection();

        // Test tenant A context
        $this->withTenant(self::TENANT_A_SLUG, function () use ($connection) {
            $this->withoutDoctrineTenantFilter(function () use ($connection) {
                $result = $connection->executeQuery('SELECT * FROM test_products ORDER BY id');
                $products = $result->fetchAllAssociative();

                $this->assertCount(
                    self::TENANT_A_PRODUCTS,
                    $products,
                    'RLS should limit native SQL query results to tenant A products only'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_A_SLUG,
                        $product['name'],
                        'All native SQL query results should belong to tenant A'
                    );
                }
            });
        });

        // Test tenant B context
        $this->withTenant(self::TENANT_B_SLUG, function () use ($connection) {
            $this->withoutDoctrineTenantFilter(function () use ($connection) {
                $result = $connection->executeQuery('SELECT * FROM test_products ORDER BY id');
                $products = $result->fetchAllAssociative();

                $this->assertCount(
                    self::TENANT_B_PRODUCTS,
                    $products,
                    'RLS should limit native SQL query results to tenant B products only'
                );

                foreach ($products as $product) {
                    $this->assertStringContainsString(
                        self::TENANT_B_SLUG,
                        $product['name'],
                        'All native SQL query results should belong to tenant B'
                    );
                }
            });
        });
    }

    /**
     * Test that PostgreSQL session variable is properly set and cleared.
     */
    public function testPostgreSqlSessionVariableManagement(): void
    {
        $connection = $this->getEntityManager()->getConnection();

        // Initially, no tenant should be set
        $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
        $currentTenantId = $result->fetchOne();
        $this->assertEmpty($currentTenantId, 'Initially, no tenant ID should be set');

        // Test that session variable is set within tenant context
        $tenantA = $this->getTenantRegistry()->findBySlug(self::TENANT_A_SLUG);
        $this->assertNotNull($tenantA);

        $this->withTenant(self::TENANT_A_SLUG, function () use ($connection, $tenantA) {
            $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
            $currentTenantId = $result->fetchOne();
            $this->assertSame(
                (string) $tenantA->getId(),
                $currentTenantId,
                'Session variable should be set to tenant A ID'
            );
        });

        // After exiting tenant context, session variable should be cleared
        $result = $connection->executeQuery("SELECT current_setting('app.tenant_id', true)");
        $currentTenantId = $result->fetchOne();
        $this->assertEmpty($currentTenantId, 'Session variable should be cleared after exiting tenant context');
    }

    /**
     * Test nested tenant contexts.
     */
    public function testNestedTenantContexts(): void
    {
        $productRepository = $this->getEntityManager()->getRepository(TestProduct::class);

        $this->withTenant(self::TENANT_A_SLUG, function () use ($productRepository) {
            // In tenant A context
            $productsA = $productRepository->findAll();
            $this->assertCount(self::TENANT_A_PRODUCTS, $productsA);

            $this->withTenant(self::TENANT_B_SLUG, function () use ($productRepository) {
                // In nested tenant B context
                $productsB = $productRepository->findAll();
                $this->assertCount(self::TENANT_B_PRODUCTS, $productsB);
            });

            // Back in tenant A context
            $productsA2 = $productRepository->findAll();
            $this->assertCount(self::TENANT_A_PRODUCTS, $productsA2);
        });
    }
}