<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final readonly class TenantContextController
{
    public function __construct(private TenantContextInterface $tenantContext)
    {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenantContext->getTenant();

        return new Response(null === $tenant ? 'none' : (string) $tenant->getId());
    }
}
