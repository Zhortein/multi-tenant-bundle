<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Toolkit;

use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestProduct;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

/**
 * Test data builder for creating tenant-aware test entities.
 *
 * This class provides methods to seed test data for different tenants,
 * ensuring proper tenant isolation and data consistency in tests.
 */
class TestData
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRegistryInterface $tenantRegistry,
    ) {
    }

    /**
     * Seed test products for a specific tenant.
     *
     * @param string $tenantId The tenant ID or slug
     * @param int    $count    Number of products to create
     *
     * @return TestProduct[] Array of created products
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function seedProducts(string $tenantId, int $count): array
    {
        $tenant = $this->resolveTenant($tenantId);
        $products = [];

        for ($i = 1; $i <= $count; ++$i) {
            $product = new TestProduct();
            $product->setName(sprintf('Product %d for %s', $i, $tenant->getSlug()));
            $product->setPrice(sprintf('%.2f', 10.00 + $i * 5.50));
            $product->setTenant($tenant);

            $this->entityManager->persist($product);
            $products[] = $product;
        }

        $this->entityManager->flush();

        return $products;
    }

    /**
     * Seed test tenants.
     *
     * @param array<string, array{name: string, active?: bool}> $tenantData Array of tenant data keyed by slug
     *
     * @return TestTenant[] Array of created tenants
     */
    public function seedTenants(array $tenantData): array
    {
        $tenants = [];

        foreach ($tenantData as $slug => $data) {
            $tenant = new TestTenant();
            $tenant->setSlug($slug);
            $tenant->setName($data['name']);
            $tenant->setActive($data['active'] ?? true);

            $this->entityManager->persist($tenant);
            $tenants[] = $tenant;
        }

        $this->entityManager->flush();

        return $tenants;
    }

    /**
     * Create a single test tenant.
     *
     * @param string $slug   The tenant slug
     * @param string $name   The tenant name
     * @param bool   $active Whether the tenant is active
     *
     * @return TestTenant The created tenant
     */
    public function createTenant(string $slug, string $name, bool $active = true): TestTenant
    {
        $tenant = new TestTenant();
        $tenant->setSlug($slug);
        $tenant->setName($name);
        $tenant->setActive($active);

        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        return $tenant;
    }

    /**
     * Create a single test product for a specific tenant.
     *
     * @param string $tenantId The tenant ID or slug
     * @param string $name     The product name
     * @param string $price    The product price
     *
     * @return TestProduct The created product
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function createProduct(string $tenantId, string $name, string $price): TestProduct
    {
        $tenant = $this->resolveTenant($tenantId);

        $product = new TestProduct();
        $product->setName($name);
        $product->setPrice($price);
        $product->setTenant($tenant);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    /**
     * Clear all test data from the database.
     *
     * This method removes all test products and tenants.
     * Use with caution in tests.
     */
    public function clearAll(): void
    {
        // Clear products first due to potential foreign key constraints
        $this->entityManager->createQuery('DELETE FROM ' . TestProduct::class)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . TestTenant::class)->execute();
    }

    /**
     * Clear test products for a specific tenant.
     *
     * @param string $tenantId The tenant ID or slug
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function clearProductsForTenant(string $tenantId): void
    {
        $tenant = $this->resolveTenant($tenantId);

        $this->entityManager->createQuery(
            'DELETE FROM ' . TestProduct::class . ' p WHERE p.tenant = :tenant'
        )->setParameter('tenant', $tenant)->execute();
    }

    /**
     * Get all products for a specific tenant.
     *
     * @param string $tenantId The tenant ID or slug
     *
     * @return TestProduct[] Array of products
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function getProductsForTenant(string $tenantId): array
    {
        $tenant = $this->resolveTenant($tenantId);

        return $this->entityManager->createQuery(
            'SELECT p FROM ' . TestProduct::class . ' p WHERE p.tenant = :tenant ORDER BY p.id'
        )->setParameter('tenant', $tenant)->getResult();
    }

    /**
     * Count products for a specific tenant.
     *
     * @param string $tenantId The tenant ID or slug
     *
     * @return int Number of products
     *
     * @throws \RuntimeException If tenant is not found
     */
    public function countProductsForTenant(string $tenantId): int
    {
        $tenant = $this->resolveTenant($tenantId);

        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(p.id) FROM ' . TestProduct::class . ' p WHERE p.tenant = :tenant'
        )->setParameter('tenant', $tenant)->getSingleScalarResult();
    }

    /**
     * Resolve a tenant by ID or slug.
     *
     * @param string $tenantId The tenant ID or slug
     *
     * @return TenantInterface The resolved tenant
     *
     * @throws \RuntimeException If the tenant cannot be found
     */
    private function resolveTenant(string $tenantId): TenantInterface
    {
        // Try to find by slug first
        $tenant = $this->tenantRegistry->findBySlug($tenantId);

        if (!$tenant) {
            // Try to find by ID if slug lookup failed
            $tenant = $this->tenantRegistry->findById($tenantId);
        }

        if (!$tenant) {
            throw new \RuntimeException(sprintf('Tenant with ID/slug "%s" not found', $tenantId));
        }

        return $tenant;
    }
}