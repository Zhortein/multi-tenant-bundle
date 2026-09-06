<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Materialized, lexicographically ordered full keys. No directories, duplicates or foreign keys. */
final readonly class BackendObjectPage
{
    /** @param array<array-key, string> $keys Must contain at most the requested limit. */
    public function __construct(public array $keys, public bool $hasMore = false)
    {
        foreach ($keys as $key) {
            Internal\Validation::nonEmptyString($key);
        }
    }
}
