<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQL filter that automatically adds tenant constraints to queries.
 *
 * This filter ensures that entities implementing TenantOwnedEntityInterface
 * are automatically filtered to only return data for the current tenant.
 */
class TenantDoctrineFilter extends SQLFilter
{
    /**
     * Adds the tenant constraint to the SQL query.
     *
     * @param ClassMetadata<object> $targetEntity     The entity metadata
     * @param string                $targetTableAlias The table alias in the query
     *
     * @return string The SQL constraint or empty string if not applicable
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        // Only apply filter to entities that implement TenantOwnedEntityInterface
        if (!is_subclass_of($targetEntity->getName(), TenantOwnedEntityInterface::class)) {
            return '';
        }

        // Ensure the entity has a tenant association
        if (!$targetEntity->hasAssociation('tenant')) {
            return '';
        }

        // Get the tenant ID parameter
        try {
            $tenantIdParameter = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException) {
            return '';
        }

        if (empty($tenantIdParameter)) {
            return '';
        }

        // Get the join column name for the tenant association
        $associationMapping = $targetEntity->getAssociationMapping('tenant');
        $joinColumnName = $associationMapping['joinColumns'][0]['name'] ?? 'tenant_id';

        return sprintf('%s.%s = %s', $targetTableAlias, $joinColumnName, $tenantIdParameter);
    }
}
