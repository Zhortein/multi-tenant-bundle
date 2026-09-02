<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Http\TenantRequestContextLoaderInterface;

final readonly class TenantContextController
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantRequestContextLoaderInterface $loader,
    ) {
    }

    public function context(): Response
    {
        $tenant = $this->tenantContext->getTenant();

        return new Response(null === $tenant ? 'none' : (string) $tenant->getId());
    }

    public function load(Request $request): Response
    {
        $this->loader->load($request);

        return $this->context();
    }
}
