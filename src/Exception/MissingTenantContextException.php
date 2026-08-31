<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class MissingTenantContextException extends \LogicException implements MultiTenantException
{
}
