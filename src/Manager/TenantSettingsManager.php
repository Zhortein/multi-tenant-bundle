<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Manager;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

/**
 * Manages tenant-specific settings with caching support.
 *
 * This service provides access to tenant settings with fallback to default values
 * and caching for performance optimization.
 */
final readonly class TenantSettingsManager implements TenantSettingsManagerInterface
{
    public function __construct(
        private TenantContextInterface  $tenantContext,
        private TenantSettingRepository $settingRepository,
        private CacheItemPoolInterface  $cache,
        private ParameterBagInterface   $parameterBag,
    ) {
    }

    /**
     * Retrieves a setting value with optional default fallback.
     *
     * @param string $key The setting key
     * @param mixed $default Default value if setting is not found
     *
     * @return mixed The setting value or default
     * @throws InvalidArgumentException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $this->parameterBag->get("zhortein_multi_tenant.default_settings.$key") ?? $default;
    }

    /**
     * Retrieves a required setting value, throws exception if not found.
     *
     * @param string $key The setting key
     *
     * @return mixed The setting value
     *
     * @throws \RuntimeException|InvalidArgumentException If the setting is not found
     */
    public function getRequired(string $key): mixed
    {
        $value = $this->get($key);

        if (null === $value) {
            throw new \RuntimeException("Tenant setting '$key' is required but not set.");
        }

        return $value;
    }

    /**
     * Returns all settings for the current tenant.
     *
     * @return array<string, mixed> Array of setting key-value pairs
     *
     * @throws \RuntimeException|InvalidArgumentException If no tenant is set in context
     */
    public function all(): array
    {
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            throw new \RuntimeException('No tenant set in context');
        }

        $cacheKey = 'zhortein_tenant_settings_'.$tenant->getId();

        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            $settings = $this->settingRepository->findAllForTenant($tenant);

            $data = [];
            foreach ($settings as $setting) {
                $data[$setting->getKey()] = $setting->getValue();
            }

            $item->set($data);
            $this->cache->save($item);
        }

        return $item->get();
    }

    /**
     * Clears the cache for the current tenant's settings.
     *
     * @throws \RuntimeException|InvalidArgumentException If no tenant is set in context
     */
    public function clearCache(): void
    {
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            throw new \RuntimeException('No tenant set in context');
        }

        $cacheKey = 'zhortein_tenant_settings_'.$tenant->getId();
        $this->cache->deleteItem($cacheKey);
    }
}
