<?php

namespace Zhortein\MultiTenantBundle\Doctrine;

interface TenantOwnedEntityInterface
{
    public function getTenant(): ?\Zhortein\MultiTenantBundle\Entity\TenantInterface;
}