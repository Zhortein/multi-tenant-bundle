<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final readonly class TenantCacheKeyPrefixer
{
    public function __construct(private TenantContextInterface $tenantContext)
    {
    }

    public function prefix(): string
    {
        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            throw new TenantCacheException('Tenant-aware cache operations require an active tenant context. Use a separate explicit cache service for global data.');
        }

        $identifier = (string) $tenant->getId();
        if ('' === $identifier || str_contains($identifier, "\0")) {
            throw new TenantCacheException('The active tenant has an unsafe cache identifier.');
        }

        return 'tenant_'.hash('sha256', $identifier).'_';
    }

    public function key(string $key): string
    {
        return $this->prefix().$key;
    }
}
