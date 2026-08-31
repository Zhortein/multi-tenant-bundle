<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class InvalidTenantMappingException extends \LogicException implements MultiTenantException
{
}
