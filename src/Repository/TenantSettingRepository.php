<?php

namespace Zhortein\MultiTenantBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\TenantSetting;

/**
 * @extends ServiceEntityRepository<TenantSetting>
 */
final class TenantSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantSetting::class);
    }

    /**
     * @return TenantSetting[]
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
}