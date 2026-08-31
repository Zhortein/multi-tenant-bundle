<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class GlobalDoctrineScopeException extends \RuntimeException implements MultiTenantException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?\Throwable $restorationFailure = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRestorationFailure(): ?\Throwable
    {
        return $this->restorationFailure;
    }
}
