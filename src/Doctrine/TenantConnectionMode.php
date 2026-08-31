<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

enum TenantConnectionMode: string
{
    case TENANT = 'tenant';
    case GLOBAL = 'global';
    case NONE = 'none';
}
