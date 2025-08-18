<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Psr\Log\LoggerInterface;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

/**
 * Doctrine SQL filter that automatically adds tenant constraints to queries.
 *
 * This filter ensures that entities implementing TenantOwnedEntityInterface
 * or marked with AsTenantAware attribute are automatically filtered to only
 * return data for the current tenant.
 *
 * Features:
 * - Safely skips entities without tenant columns
 * - Properly types parameters (UUID vs int) based on mapping
 * - Handles DQL with multiple aliases and joins
 * - Provides debug logging when filter cannot apply
 */
class TenantDoctrineFilter extends SQLFilter
{
    private ?LoggerInterface $logger = null;

    /**
     * Sets the logger for debug information.
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Adds the tenant constraint to the SQL query.
     *
     * @param ClassMetadata<object> $targetEntity     The entity metadata
     * @param string                $targetTableAlias The table alias in the query
     *
     * @return string The SQL constraint or empty string if not applicable
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $entityClass = $targetEntity->getName();

        // Check if entity is tenant-aware (interface or attribute)
        if (!$this->isTenantAwareEntity($targetEntity)) {
            $this->logger?->debug('Entity is not tenant-aware, skipping filter', [
                'entity' => $entityClass,
                'reason' => 'not_tenant_aware',
            ]);

            return '';
        }

        // Get tenant field name from attribute or default to 'tenant'
        $tenantField = $this->getTenantFieldName($targetEntity);

        // Ensure the entity has a tenant association or field
        if (!$this->hasTenantColumn($targetEntity, $tenantField)) {
            $this->logger?->debug('Entity has no tenant column, skipping filter', [
                'entity' => $entityClass,
                'tenant_field' => $tenantField,
                'reason' => 'no_tenant_column',
            ]);

            return '';
        }

        // Get the tenant ID parameter
        try {
            $tenantIdParameter = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException) {
            $this->logger?->debug('No tenant_id parameter set, skipping filter', [
                'entity' => $entityClass,
                'reason' => 'no_tenant_parameter',
            ]);

            return '';
        }

        if (empty($tenantIdParameter)) {
            $this->logger?->debug('Empty tenant_id parameter, skipping filter', [
                'entity' => $entityClass,
                'reason' => 'empty_tenant_parameter',
            ]);

            return '';
        }

        // Get the column name and type for the tenant field
        $columnInfo = $this->getTenantColumnInfo($targetEntity, $tenantField);
        if (null === $columnInfo) {
            $this->logger?->debug('Could not determine tenant column info, skipping filter', [
                'entity' => $entityClass,
                'tenant_field' => $tenantField,
                'reason' => 'no_column_info',
            ]);

            return '';
        }

        // Create properly typed parameter
        $typedParameter = $this->createTypedParameter($tenantIdParameter, $columnInfo['type']);

        // Handle multiple aliases by checking if the alias contains the table name
        $constraint = sprintf('%s.%s = %s', $targetTableAlias, $columnInfo['name'], $typedParameter);

        $this->logger?->debug('Applied tenant filter constraint', [
            'entity' => $entityClass,
            'alias' => $targetTableAlias,
            'column' => $columnInfo['name'],
            'type' => $columnInfo['type'],
            'constraint' => $constraint,
        ]);

        return $constraint;
    }

    /**
     * Checks if an entity is tenant-aware (implements interface or has attribute).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function isTenantAwareEntity(ClassMetadata $metadata): bool
    {
        $entityClass = $metadata->getName();

        // Check if implements TenantOwnedEntityInterface
        if (is_subclass_of($entityClass, TenantOwnedEntityInterface::class)) {
            return true;
        }

        // Check if has AsTenantAware attribute
        if (class_exists($entityClass)) {
            $reflectionClass = new \ReflectionClass($entityClass);
            $attributes = $reflectionClass->getAttributes(AsTenantAware::class);

            return !empty($attributes);
        }

        return false;
    }

    /**
     * Gets the tenant field name from AsTenantAware attribute or defaults to 'tenant'.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function getTenantFieldName(ClassMetadata $metadata): string
    {
        $entityClass = $metadata->getName();

        if (class_exists($entityClass)) {
            $reflectionClass = new \ReflectionClass($entityClass);
            $attributes = $reflectionClass->getAttributes(AsTenantAware::class);

            if (!empty($attributes)) {
                /** @var AsTenantAware $attribute */
                $attribute = $attributes[0]->newInstance();

                return $attribute->tenantField;
            }
        }

        return 'tenant';
    }

    /**
     * Checks if the entity has a tenant column (association or direct field).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function hasTenantColumn(ClassMetadata $metadata, string $tenantField): bool
    {
        // Check for association
        if ($metadata->hasAssociation($tenantField)) {
            return true;
        }

        // Check for direct field
        if ($metadata->hasField($tenantField)) {
            return true;
        }

        return false;
    }

    /**
     * Gets tenant column information (name and type).
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array{name: string, type: string}|null
     */
    private function getTenantColumnInfo(ClassMetadata $metadata, string $tenantField): ?array
    {
        // Handle association
        if ($metadata->hasAssociation($tenantField)) {
            $associationMapping = $metadata->getAssociationMapping($tenantField);
            $joinColumnName = $associationMapping['joinColumns'][0]['name'] ?? $tenantField.'_id';

            // Determine type based on target entity's ID field
            $targetEntity = $associationMapping['targetEntity'];
            if (is_string($targetEntity) && class_exists($targetEntity)) {
                try {
                    $targetMetadata = $this->getEntityManager()->getClassMetadata($targetEntity);
                    $idFieldMapping = $targetMetadata->getFieldMapping($targetMetadata->getSingleIdentifierFieldName());
                    $idType = $idFieldMapping['type'] ?? Types::INTEGER;
                } catch (\Exception) {
                    $idType = Types::INTEGER; // Default fallback
                }
            } else {
                $idType = Types::INTEGER; // Default fallback
            }

            return [
                'name' => is_string($joinColumnName) ? $joinColumnName : $tenantField.'_id',
                'type' => is_string($idType) ? $idType : Types::INTEGER,
            ];
        }

        // Handle direct field
        if ($metadata->hasField($tenantField)) {
            $fieldMapping = $metadata->getFieldMapping($tenantField);

            $columnName = $fieldMapping['columnName'] ?? $tenantField;
            $fieldType = $fieldMapping['type'] ?? Types::INTEGER;

            return [
                'name' => is_string($columnName) ? $columnName : $tenantField,
                'type' => is_string($fieldType) ? $fieldType : Types::INTEGER,
            ];
        }

        return null;
    }

    /**
     * Creates a properly typed parameter for the SQL query.
     */
    private function createTypedParameter(string $value, string $type): string
    {
        return match ($type) {
            Types::GUID, 'uuid' => "'{$value}'", // Quote UUIDs
            Types::STRING, Types::TEXT => "'{$value}'", // Quote strings
            default => $value, // Numeric types don't need quotes
        };
    }

    /**
     * Gets the entity manager from the filter.
     *
     * This method uses reflection to access the protected entityManager property
     * since it's not exposed by the parent SQLFilter class.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        $reflection = new \ReflectionClass(SQLFilter::class);
        $property = $reflection->getProperty('em');

        $entityManager = $property->getValue($this);

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Unable to retrieve EntityManager from SQLFilter');
        }

        return $entityManager;
    }
}
