<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestProduct;

/**
 * Test controller for HTTP integration tests.
 */
class TestController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
    ) {
    }

    #[Route('/test/products', name: 'test_products', methods: ['GET'])]
    public function products(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $repository = $this->entityManager->getRepository(TestProduct::class);
        $products = $repository->findAll();

        $data = [
            'tenant' => $tenant ? $tenant->getSlug() : null,
            'count' => count($products),
            'products' => array_map(function (TestProduct $product) {
                return [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                ];
            }, $products),
        ];

        return new JsonResponse($data);
    }

    #[Route('/test/tenant-info', name: 'test_tenant_info', methods: ['GET'])]
    public function tenantInfo(): Response
    {
        $tenant = $this->tenantContext->getTenant();

        $data = [
            'has_tenant' => $this->tenantContext->hasTenant(),
            'tenant' => $tenant ? [
                'id' => $tenant->getId(),
                'slug' => $tenant->getSlug(),
                'name' => $tenant->getName(),
            ] : null,
        ];

        return new JsonResponse($data);
    }

    #[Route('/{tenant}/test/products', name: 'test_products_with_path', methods: ['GET'])]
    public function productsWithPath(string $tenant): Response
    {
        // The tenant should be resolved by the path resolver
        return $this->products();
    }
}
