<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class TenantMismatchException extends \DomainException implements MultiTenantException
{
}
