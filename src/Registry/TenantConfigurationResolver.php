<?php

namespace Zhortein\MultiTenantBundle\Resolver;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

readonly class TenantConfigurationResolver
{
    public function __construct(
        private TenantSettingRepository $settingRepository,
        private CacheItemPoolInterface $cache,
        private ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * @param TenantInterface $tenant
     * @param string $key
     * @param string|null $default
     *
     * @return string|null
     */
    public function getSetting(TenantInterface $tenant, string $key, ?string $default = null): ?string
    {
        $cacheKey = 'tenant_config_' . $tenant->getId();

        $settings = $this->cache->getItem($cacheKey);
        if (!$settings->isHit()) {
            $allSettings = [];
            foreach ($this->settingRepository->findAllForTenant($tenant) as $setting) {
                $allSettings[$setting->getKey()] = $setting->getValue();
            }
            $settings->set($allSettings);
            $this->cache->save($settings);
        }

        $data = $settings->get();
        return $data[$key] ?? $default;
    }

    public function getMailerDsn(TenantInterface $tenant): string
    {
        return $this->getSetting($tenant, 'mailer_dsn', $this->parameterBag->get('env(MAILER_DSN)'));
    }

    public function getMessengerDsn(TenantInterface $tenant): string
    {
        return $this->getSetting($tenant, 'messenger_transport_dsn', $this->parameterBag->get('env(MESSENGER_TRANSPORT_DSN)'));
    }
}
