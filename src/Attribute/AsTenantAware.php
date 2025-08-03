<?php

namespace Zhortein\MultiTenantBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTenantAware
{
    public function __construct(
        public readonly string $filter = 'tenant_filter'
    ) {}
}
