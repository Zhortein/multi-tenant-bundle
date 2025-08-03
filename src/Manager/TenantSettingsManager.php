<?php
namespace Zhortein\MultiTenantBundle\Manager;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantSetting;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

final class TenantSettingsManager
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantSettingRepository $settingRepository,
        private readonly CacheItemPoolInterface $cache,
        private readonly ParameterBagInterface $parameterBag
    ) {}

    /**
     * Récupère une valeur de setting.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $this->parameterBag->get("zhortein_multi_tenant.default_settings.$key") ?? $default;
    }

    /**
     * Récupère une valeur obligatoire.
     */
    public function getRequired(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new \RuntimeException("Tenant setting '$key' is required but not set.");
        }

        return $value;
    }

    /**
     * Retourne tous les paramètres du tenant courant.
     */
    public function all(): array
    {
        $tenant = $this->tenantContext->getTenant();
        $cacheKey = 'zhortein_tenant_settings_' . $tenant->getId();

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
}
