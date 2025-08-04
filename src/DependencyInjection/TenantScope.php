<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\DependencyInjection;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Tenant scope for dependency injection container.
 *
 * This scope allows services to be scoped to a specific tenant,
 * ensuring that tenant-specific services are properly isolated.
 */
final class TenantScope
{
    public const SCOPE_NAME = 'tenant';

    private ?TenantInterface $currentTenant = null;
    
    /**
     * @var array<string, object>
     */
    private array $services = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, callable $factory): mixed
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant === null) {
            throw new \RuntimeException('No tenant is currently set in the context.');
        }

        $tenantId = $tenant->getId();

        // If tenant changed, clear services for previous tenant
        if ($this->currentTenant === null || $this->currentTenant->getId() !== $tenantId) {
            $this->services = [];
            $this->currentTenant = $tenant;
        }

        // Return existing service if already created for this tenant
        /** @phpstan-ignore-next-line */
        if (isset($this->services[$tenantId][$id])) {
            /** @phpstan-ignore-next-line */
            return $this->services[$tenantId][$id];
        }

        // Create new service for this tenant
        $service = $factory();
        /** @phpstan-ignore-next-line */
        $this->services[$tenantId][$id] = $service;

        return $service;
    }

    /**
     * Clears all services for the current tenant.
     */
    public function clear(): void
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant !== null) {
            $tenantId = $tenant->getId();
            unset($this->services[$tenantId]);
        }
    }

    /**
     * Clears all services for all tenants.
     */
    public function clearAll(): void
    {
        $this->services = [];
        $this->currentTenant = null;
    }

    /**
     * Gets the current tenant.
     */
    public function getCurrentTenant(): ?TenantInterface
    {
        return $this->currentTenant;
    }
}