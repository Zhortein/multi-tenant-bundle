<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Test;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Executes test code under an explicit tenant and restores the previous context.
 */
final readonly class TenantContextScope
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    public function run(TenantInterface $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenantContext->getTenant();

        try {
            $this->tenantContext->setTenant($tenant);

            return $callback();
        } finally {
            if (null === $previousTenant) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->setTenant($previousTenant);
            }
        }
    }
}
