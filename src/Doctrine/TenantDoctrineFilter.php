<?php

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class TenantDoctrineFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if (!is_subclass_of($targetEntity->getName(), TenantOwnedEntityInterface::class)) {
            return '';
        }

        if (!$targetEntity->hasAssociation('tenant')) {
            return '';
        }

        $value = $this->getParameter('tenant_id');

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $value);
    }
}