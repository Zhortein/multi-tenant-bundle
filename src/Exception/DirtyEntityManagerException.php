<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class DirtyEntityManagerException extends \LogicException implements MultiTenantException
{
}
