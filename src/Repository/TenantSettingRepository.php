<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\TenantSetting;

/**
 * Repository for TenantSetting entities.
 *
 * Provides methods to query tenant-specific settings with optimized queries.
 *
 * @extends ServiceEntityRepository<TenantSetting>
 */
class TenantSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantSetting::class);
    }

    /**
     * Finds all settings for a specific tenant.
     *
     * @param TenantInterface $tenant The tenant to find settings for
     *
     * @return TenantSetting[] Array of tenant settings ordered by key
     */
    public function findAllForTenant(TenantInterface $tenant): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ts.key', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds a specific setting for a tenant by key.
     *
     * @param TenantInterface $tenant The tenant
     * @param string          $key    The setting key
     *
     * @return TenantSetting|null The setting or null if not found
     */
    public function findOneByTenantAndKey(TenantInterface $tenant, string $key): ?TenantSetting
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.tenant = :tenant')
            ->andWhere('ts.key = :key')
            ->setParameter('tenant', $tenant)
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Creates or updates a setting for a tenant.
     *
     * @param TenantInterface $tenant The tenant
     * @param string          $key    The setting key
     * @param string|null     $value  The setting value
     *
     * @return TenantSetting The created or updated setting
     */
    public function createOrUpdate(TenantInterface $tenant, string $key, ?string $value): TenantSetting
    {
        $setting = $this->findOneByTenantAndKey($tenant, $key);

        if (null === $setting) {
            $setting = new TenantSetting();
            $setting->setTenant($tenant);
            $setting->setKey($key);
        }

        $setting->setValue($value);

        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();

        return $setting;
    }

    /**
     * Removes a setting for a tenant.
     *
     * @param TenantInterface $tenant The tenant
     * @param string          $key    The setting key
     *
     * @return bool True if setting was removed, false if not found
     */
    public function removeByTenantAndKey(TenantInterface $tenant, string $key): bool
    {
        $setting = $this->findOneByTenantAndKey($tenant, $key);

        if (null === $setting) {
            return false;
        }

        $this->getEntityManager()->remove($setting);
        $this->getEntityManager()->flush();

        return true;
    }
}
