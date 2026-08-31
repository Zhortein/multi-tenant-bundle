<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class InvalidTenantIdentifierException extends \InvalidArgumentException implements MultiTenantException
{
}
