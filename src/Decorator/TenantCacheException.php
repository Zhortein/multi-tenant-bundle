<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Zhortein\MultiTenantBundle\Exception\MultiTenantException;

final class TenantCacheException extends \RuntimeException implements MultiTenantException
{
}
