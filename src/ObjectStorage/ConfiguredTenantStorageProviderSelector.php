<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

final readonly class ConfiguredTenantStorageProviderSelector implements TenantStorageProviderSelectorInterface
{
    /** @param array<string|int, string> $tenantOverrides */
    public function __construct(private string $defaultProvider, private array $tenantOverrides = [])
    {
        Validation::identifier($defaultProvider);
        foreach ($tenantOverrides as $tenantId => $provider) {
            Validation::tenantId($tenantId);
            Validation::identifier($provider);
        }
    }

    public function selectForNewObject(TenantInterface $tenant): string
    {
        return $this->tenantOverrides[Validation::tenantId($tenant->getId())] ?? $this->defaultProvider;
    }
}
