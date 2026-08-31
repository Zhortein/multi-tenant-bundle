<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

interface GlobalDoctrineScopeInterface
{
    /**
     * Runs an explicitly global ORM operation. This is not an authorization boundary.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
