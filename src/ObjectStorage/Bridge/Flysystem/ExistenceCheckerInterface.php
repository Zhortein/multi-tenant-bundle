<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem;

/** Distinguishes absence from an unavailable target, without changing the qualified key. */
interface ExistenceCheckerInterface
{
    public function exists(string $qualifiedKey): bool;
}
