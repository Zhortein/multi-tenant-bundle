<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class TenantContextTransitionException extends \RuntimeException implements MultiTenantException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?\Throwable $restorationFailure = null,
        private readonly ?\Throwable $cleanupFailure = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRestorationFailure(): ?\Throwable
    {
        return $this->restorationFailure;
    }

    public function getCleanupFailure(): ?\Throwable
    {
        return $this->cleanupFailure;
    }
}
