<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

/**
 * Exception thrown when tenant resolution fails in strict mode.
 */
class TenantResolutionException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        string $message,
        private readonly array $diagnostics = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Gets diagnostic information about the resolution failure.
     *
     * @return array<string, mixed>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
