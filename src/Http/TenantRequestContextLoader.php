<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

final readonly class TenantRequestContextLoader implements TenantRequestContextLoaderInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantResolverInterface $tenantResolver,
        private ?TenantStateResetterInterface $stateResetter = null,
    ) {
    }

    public function load(Request $request): ?TenantInterface
    {
        $this->resetState();

        try {
            $tenant = $this->tenantResolver->resolveTenant($request);
            if (null !== $tenant) {
                $this->tenantContext->setTenant($tenant);
            }

            return $tenant;
        } catch (\Throwable $operationFailure) {
            try {
                $this->resetState();
            } catch (\Throwable $cleanupFailure) {
                throw new TenantContextTransitionException('Tenant request resolution failed and tenant state could not be reset.', 0, $operationFailure, null, $cleanupFailure);
            }

            throw $operationFailure;
        }
    }

    private function resetState(): void
    {
        if (null !== $this->stateResetter) {
            $this->stateResetter->reset();

            return;
        }

        $this->tenantContext->reset();
    }
}
