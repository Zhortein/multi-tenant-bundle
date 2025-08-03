<?php

namespace Zhortein\MultiTenantBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\TenantSetting;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

final class TenantSettingsManager
{
    private array $cache = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSettingRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    private function getCurrentTenant(): TenantInterface
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException('No tenant available in context.');
        }

        return $this->tenantContext->getTenant();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $tenantId = $this->getCurrentTenant()->getId();

        if (!isset($this->cache[$tenantId][$key])) {
            $setting = $this->repository->findOneBy([
                'tenant' => $tenantId,
                'key' => $key,
            ]);

            $this->cache[$tenantId][$key] = $setting?->getValue() ?? $default;
        }

        return $this->cache[$tenantId][$key];
    }

    public function set(string $key, mixed $value): void
    {
        $tenant = $this->getCurrentTenant();
        $tenantId = $tenant->getId();

        $setting = $this->repository->findOneBy([
            'tenant' => $tenantId,
            'key' => $key,
        ]);

        if (!$setting) {
            $setting = new TenantSetting();
            $setting->setTenant($tenant);
            $setting->setKey($key);
        }

        $setting->setValue((string)$value);
        $this->em->persist($setting);
        $this->em->flush();

        $this->cache[$tenantId][$key] = (string)$value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, null) !== null;
    }
}