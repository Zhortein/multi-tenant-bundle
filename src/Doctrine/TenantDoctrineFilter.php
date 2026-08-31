<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Psr\Log\LoggerInterface;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantIdentifierException;
use Zhortein\MultiTenantBundle\Exception\InvalidTenantMappingException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;

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
            throw new InvalidTenantMappingException(sprintf('Tenant-aware entity "%s" has no mapped tenant field "%s".', $entityClass, $tenantField));
        }

        // Get the tenant ID parameter
        try {
            $mode = trim($this->getParameter('tenant_context_mode'), "'\"");
        } catch (\InvalidArgumentException $exception) {
            throw new MissingTenantContextException(sprintf('Tenant protection for "%s" has no initialized context state.', $entityClass), 0, $exception);
        }
        if (TenantConnectionMode::TENANT->value !== $mode) {
            throw new MissingTenantContextException(sprintf('Tenant-aware entity "%s" cannot be queried without a tenant context.', $entityClass));
        }

        try {
            $tenantIdParameter = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException $exception) {
            throw new MissingTenantContextException(sprintf('Tenant protection for "%s" has no tenant identifier.', $entityClass), 0, $exception);
        }

        if ("''" === $tenantIdParameter || '' === trim($tenantIdParameter, "'\" ")) {
            throw new InvalidTenantIdentifierException(sprintf('Tenant protection for "%s" received an empty tenant identifier.', $entityClass));
        }

        // Get the column name and type for the tenant field
        $columnInfo = $this->getTenantColumnInfo($targetEntity, $tenantField);
        if (null === $columnInfo) {
            throw new InvalidTenantMappingException(sprintf('Unable to determine the tenant column for "%s".', $entityClass));
        }

        $unquotedTenantId = trim($tenantIdParameter, "'");
        if (in_array($columnInfo['type'], [Types::INTEGER, Types::BIGINT, Types::SMALLINT], true) && !ctype_digit($unquotedTenantId)) {
            throw new InvalidTenantIdentifierException(sprintf('Tenant identifier "%s" is invalid for numeric mapping on "%s".', $unquotedTenantId, $entityClass));
        }
        if (in_array($columnInfo['type'], [Types::GUID, 'uuid'], true)
            && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $unquotedTenantId)) {
            throw new InvalidTenantIdentifierException(sprintf('Tenant identifier "%s" is not a valid UUID for "%s".', $unquotedTenantId, $entityClass));
        }

        // Handle multiple aliases by checking if the alias contains the table name
        $constraint = sprintf('%s.%s = %s', $targetTableAlias, $columnInfo['name'], $tenantIdParameter);

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
            $joinColumnName = $associationMapping['joinColumns'][0]['name'] ?? null;
            if (!is_string($joinColumnName) || '' === $joinColumnName) {
                throw new InvalidTenantMappingException(sprintf('Tenant association "%s::%s" requires one named join column.', $metadata->getName(), $tenantField));
            }

            return [
                'name' => $joinColumnName,
                // SQLFilter already quotes through the active DBAL connection. Association
                // identifier metadata is not exposed through SQLFilter's public API.
                'type' => Types::STRING,
            ];
        }

        // Handle direct field
        if ($metadata->hasField($tenantField)) {
            $fieldMapping = $metadata->getFieldMapping($tenantField);

            $columnName = $fieldMapping['columnName'] ?? null;
            $fieldType = $fieldMapping['type'] ?? null;

            if (!is_string($columnName) || '' === $columnName || !is_string($fieldType) || '' === $fieldType) {
                throw new InvalidTenantMappingException(sprintf('Tenant field "%s::%s" has incomplete column metadata.', $metadata->getName(), $tenantField));
            }

            return [
                'name' => $columnName,
                'type' => $fieldType,
            ];
        }

        return null;
    }
}
