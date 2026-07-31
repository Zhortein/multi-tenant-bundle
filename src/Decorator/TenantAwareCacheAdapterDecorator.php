<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Adapts a Symfony cache pool while isolating every operation by tenant namespace.
 *
 * @internal
 */
final readonly class TenantAwareCacheAdapterDecorator implements AdapterInterface, CacheInterface
{
    private TenantCacheKeyPrefixer $keyPrefixer;

    public function __construct(
        private CacheItemPoolInterface $decorated,
        private TenantContextInterface $tenantContext,
        private bool $enabled = true,
    ) {
        $this->keyPrefixer = new TenantCacheKeyPrefixer($tenantContext);
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->adapter()->get($key, $callback, $beta, $metadata);
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->adapter()->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->adapter()->getItems($keys);
    }

    public function hasItem(mixed $key): bool
    {
        return $this->adapter()->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->adapter()->clear($prefix);
    }

    public function delete(string $key): bool
    {
        return $this->adapter()->delete($key);
    }

    public function deleteItem(mixed $key): bool
    {
        return $this->adapter()->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->adapter()->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter()->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter()->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->adapter()->commit();
    }

    private function adapter(): ProxyAdapter
    {
        $namespace = $this->enabled ? $this->keyPrefixer->prefix() : '';

        return new ProxyAdapter($this->decorated, $namespace);
    }
}
