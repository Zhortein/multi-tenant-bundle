<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestProduct;

final readonly class TestProductsController
{
    public function __construct(
        private TenantContextInterface $context,
        private EntityManagerInterface $entityManager,
        private CacheInterface $cache,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $tenant = $this->context->getTenant();
        if (null === $tenant) {
            return new JsonResponse(['error' => 'missing tenant'], 400);
        }
        $products = $this->entityManager->getRepository(TestProduct::class)->findAll();
        $this->cache->get('products_initialized', static fn (): int => count($products));

        return new JsonResponse([
            'tenant' => $tenant->getSlug(),
            'count' => count($products),
            'products' => array_map(static fn (TestProduct $product): ?string => $product->getName(), $products),
        ]);
    }
}
