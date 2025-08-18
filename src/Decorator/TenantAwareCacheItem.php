<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Psr\Cache\CacheItemInterface;

/**
 * Wraps a cache item to maintain the original key while using a prefixed key internally.
 */
final class TenantAwareCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly CacheItemInterface $decorated,
        private readonly string $originalKey,
    ) {
    }

    public function getKey(): string
    {
        return $this->originalKey;
    }

    public function get(): mixed
    {
        return $this->decorated->get();
    }

    public function isHit(): bool
    {
        return $this->decorated->isHit();
    }

    public function set(mixed $value): static
    {
        $this->decorated->set($value);

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->decorated->expiresAt($expiration);

        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        $this->decorated->expiresAfter($time);

        return $this;
    }

    /**
     * Gets the decorated cache item for internal use.
     */
    public function getDecoratedItem(): CacheItemInterface
    {
        return $this->decorated;
    }
}
