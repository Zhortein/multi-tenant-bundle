<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class MissingTenantStampException extends \RuntimeException implements MultiTenantException
{
}
