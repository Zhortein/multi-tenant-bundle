<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Lifecycle;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;

final readonly class TenantExecutionBoundary implements TenantExecutionBoundaryInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private ?TenantStateResetterInterface $stateResetter = null,
    ) {
    }

    public function run(callable $operation): mixed
    {
        $this->resetState();
        $result = null;
        $operationFailure = null;

        try {
            $result = $operation();
        } catch (\Throwable $exception) {
            $operationFailure = $exception;
        }

        try {
            $this->resetState();
        } catch (\Throwable $cleanupFailure) {
            throw new TenantContextTransitionException('Tenant execution boundary could not reset tenant state.', 0, $operationFailure ?? $cleanupFailure, null, $operationFailure ? $cleanupFailure : null);
        }

        if (null !== $operationFailure) {
            throw $operationFailure;
        }

        return $result;
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
