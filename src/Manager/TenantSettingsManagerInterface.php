<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Manager;

/**
 * Interface for tenant settings management.
 *
 * This interface defines the contract for managing tenant-specific settings
 * with caching support and fallback to default values.
 */
interface TenantSettingsManagerInterface
{
    /**
     * Retrieves a setting value with optional default fallback.
     *
     * @param string $key     The setting key
     * @param mixed  $default Default value if setting is not found
     *
     * @return mixed The setting value or default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Retrieves a required setting value, throws exception if not found.
     *
     * @param string $key The setting key
     *
     * @return mixed The setting value
     *
     * @throws \RuntimeException If the setting is not found
     */
    public function getRequired(string $key): mixed;

    /**
     * Returns all settings for the current tenant.
     *
     * @return array<string, mixed> Array of setting key-value pairs
     *
     * @throws \RuntimeException If no tenant is set in context
     */
    public function all(): array;

    /**
     * Clears the cache for the current tenant's settings.
     *
     * @throws \RuntimeException If no tenant is set in context
     */
    public function clearCache(): void;
}
