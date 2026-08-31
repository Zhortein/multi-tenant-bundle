<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Zhortein\MultiTenantBundle\Exception\MultiTenantException;

final class TenantStorageException extends \RuntimeException implements MultiTenantException
{
}
