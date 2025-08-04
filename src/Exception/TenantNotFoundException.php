<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

/**
 * Exception thrown when a tenant cannot be found.
 *
 * This exception is typically thrown by tenant registries when attempting
 * to retrieve a tenant that doesn't exist in the system.
 */
final class TenantNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Tenant not found', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Creates an exception for a tenant not found by slug.
     *
     * @param string $slug The tenant slug that was not found
     */
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Tenant with slug "%s" not found', $slug));
    }

    /**
     * Creates an exception for a tenant not found by ID.
     *
     * @param string $id The tenant ID that was not found
     */
    public static function forId(string $id): self
    {
        return new self(sprintf('Tenant with ID "%s" not found', $id));
    }
}
