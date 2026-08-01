<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Test;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Base class for kernel tests that need explicit, leak-free tenant scopes.
 */
abstract class TenantKernelTestCase extends KernelTestCase
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    protected function withTenant(TenantInterface $tenant, callable $callback): mixed
    {
        return (new TenantContextScope($this->tenantContext()))->run($tenant, $callback);
    }

    protected function tenantContext(): TenantContextInterface
    {
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);

        if (!$tenantContext instanceof TenantContextInterface) {
            throw new \LogicException(sprintf('Service "%s" must implement %s.', TenantContextInterface::class, TenantContextInterface::class));
        }

        return $tenantContext;
    }

    protected function clearTenantContext(): void
    {
        if (static::$booted) {
            $this->tenantContext()->clear();
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->clearTenantContext();
        } finally {
            parent::tearDown();
        }
    }
}
