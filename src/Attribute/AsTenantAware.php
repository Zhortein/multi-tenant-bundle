<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Attribute;

/**
 * Marks an entity as tenant-aware.
 *
 * This attribute is used to identify entities that should be automatically
 * filtered by tenant context. The behavior depends on the database strategy:
 *
 * - shared-db: Entities will have tenant_id field and automatic filtering
 * - multi-db: Entities exist in tenant-specific databases without tenant_id
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTenantAware
{
    public function __construct(
        /**
         * The name of the Doctrine filter to apply (shared-db mode only).
         */
        public readonly string $filter = 'tenant_filter',

        /**
         * Whether to require tenant_id field in shared-db mode.
         * Set to false for entities that should exist in multi-db mode only.
         */
        public readonly bool $requireTenantId = true,

        /**
         * Custom tenant field name (default: 'tenant').
         */
        public readonly string $tenantField = 'tenant',
    ) {
    }
}
