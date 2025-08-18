<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Psr\SimpleCache\CacheInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Decorates PSR-16 simple cache to prefix keys with tenant ID.
 *
 * This decorator automatically prefixes cache keys with the current tenant ID
 * to ensure cache isolation between tenants. When no tenant context is available,
 * it operates as a no-op decorator, allowing for public/shared cache usage.
 */
final class TenantAwareSimpleCacheDecorator implements CacheInterface
{
    public function __construct(
        private readonly CacheInterface $decorated,
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->decorated->get($this->prefixKey($key), $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->decorated->set($this->prefixKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->decorated->delete($this->prefixKey($key));
    }

    public function clear(): bool
    {
        // Only clear tenant-specific items if we have a tenant context
        if (!$this->enabled || !$this->tenantContext->getTenant()) {
            return $this->decorated->clear();
        }

        // For tenant-aware clearing, we would need to implement a more sophisticated
        // approach to only clear items with the current tenant prefix
        // For now, delegate to the underlying cache
        return $this->decorated->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $prefixedKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $prefixedKey = $this->prefixKey($key);
            $prefixedKeys[] = $prefixedKey;
            $keyMap[$prefixedKey] = $key;
        }

        $results = $this->decorated->getMultiple($prefixedKeys, $default);

        $mappedResults = [];
        foreach ($results as $prefixedKey => $value) {
            $originalKey = $keyMap[$prefixedKey] ?? $prefixedKey;
            $mappedResults[$originalKey] = $value;
        }

        return $mappedResults;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $prefixedValues = [];

        foreach ($values as $key => $value) {
            $prefixedValues[$this->prefixKey($key)] = $value;
        }

        return $this->decorated->setMultiple($prefixedValues, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = [];

        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefixKey($key);
        }

        return $this->decorated->deleteMultiple($prefixedKeys);
    }

    public function has(string $key): bool
    {
        return $this->decorated->has($this->prefixKey($key));
    }

    /**
     * Prefixes the cache key with tenant ID if tenant context is available and decorator is enabled.
     */
    private function prefixKey(string $key): string
    {
        if (!$this->enabled) {
            return $key;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return $key;
        }

        return sprintf('tenant_%s_%s', $tenant->getId(), $key);
    }
}
