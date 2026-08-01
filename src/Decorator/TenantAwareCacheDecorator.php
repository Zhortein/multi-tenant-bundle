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
    private readonly TenantCacheKeyPrefixer $keyPrefixer;

    public function __construct(
        private readonly CacheItemPoolInterface $decorated,
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
    ) {
        $this->keyPrefixer = new TenantCacheKeyPrefixer($tenantContext);
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
            if (!$item instanceof CacheItemInterface || !is_string($prefixedKey)) {
                continue;
            }
            $originalKey = $keyMap[$prefixedKey] ?? $prefixedKey;
            $result[$originalKey] = new TenantAwareCacheItem($item, $originalKey);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        return $this->decorated->hasItem($this->prefixKey($key));
    }

    public function clear(): bool
    {
        if (!$this->enabled) {
            return $this->decorated->clear();
        }

        $this->keyPrefixer->prefix();

        throw new TenantCacheException('This PSR-6 pool cannot guarantee tenant-scoped clear operations. Delete explicit keys or use the Symfony cache adapter decorator.');
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

        if ($this->enabled) {
            throw new TenantCacheException('Tenant-aware cache only accepts items returned by this decorator.');
        }

        return $this->decorated->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($item instanceof TenantAwareCacheItem) {
            return $this->decorated->saveDeferred($item->getDecoratedItem());
        }

        if ($this->enabled) {
            throw new TenantCacheException('Tenant-aware cache only accepts items returned by this decorator.');
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

        return $this->keyPrefixer->key($key);
    }
}
