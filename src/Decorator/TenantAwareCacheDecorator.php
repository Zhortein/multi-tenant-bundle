<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Decorates PSR-6 cache pools to prefix keys with tenant ID.
 *
 * This decorator automatically prefixes cache keys with the current tenant ID
 * to ensure cache isolation between tenants. When no tenant context is available,
 * it operates as a no-op decorator, allowing for public/shared cache usage.
 */
final class TenantAwareCacheDecorator implements CacheItemPoolInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $decorated,
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
    ) {
    }

    public function getItem(string $key): CacheItemInterface
    {
        return new TenantAwareCacheItem(
            $this->decorated->getItem($this->prefixKey($key)),
            $key
        );
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $prefixedKeys = array_map([$this, 'prefixKey'], $keys);
        $items = $this->decorated->getItems($prefixedKeys);

        $result = [];
        $keyMap = array_combine($prefixedKeys, $keys);

        foreach ($items as $prefixedKey => $item) {
            if (!$item instanceof CacheItemInterface) {
                continue;
            }
            $originalKey = $keyMap[$prefixedKey] ?? $prefixedKey;
            $result[(string) $originalKey] = new TenantAwareCacheItem($item, (string) $originalKey);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        return $this->decorated->hasItem($this->prefixKey($key));
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

    public function deleteItem(string $key): bool
    {
        return $this->decorated->deleteItem($this->prefixKey($key));
    }

    public function deleteItems(array $keys): bool
    {
        $prefixedKeys = array_map([$this, 'prefixKey'], $keys);

        return $this->decorated->deleteItems($prefixedKeys);
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($item instanceof TenantAwareCacheItem) {
            return $this->decorated->save($item->getDecoratedItem());
        }

        return $this->decorated->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($item instanceof TenantAwareCacheItem) {
            return $this->decorated->saveDeferred($item->getDecoratedItem());
        }

        return $this->decorated->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->decorated->commit();
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
