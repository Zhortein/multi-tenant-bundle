<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Exception thrown when multiple resolvers return different tenants (ambiguous resolution).
 */
class AmbiguousTenantResolutionException extends TenantResolutionException
{
    /**
     * @param array<string, TenantInterface> $conflictingResults
     * @param array<string, mixed>           $diagnostics
     */
    public function __construct(
        array $conflictingResults,
        array $diagnostics = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $resolverNames = array_keys($conflictingResults);
        $tenantSlugs = array_map(fn (TenantInterface $tenant) => $tenant->getSlug(), $conflictingResults);

        $message = sprintf(
            'Ambiguous tenant resolution: resolvers %s returned different tenants: %s',
            implode(', ', $resolverNames),
            implode(', ', $tenantSlugs)
        );

        parent::__construct($message, $diagnostics, $code, $previous);
    }
}
