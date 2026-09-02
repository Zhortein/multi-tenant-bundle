<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Lifecycle;

interface TenantExecutionBoundaryInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $operation
     *
     * @return TResult
     */
    public function run(callable $operation): mixed;
}
