<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Helper for prefixing storage paths with tenant information.
 *
 * This helper provides methods to prefix file paths with tenant-specific
 * directories, ensuring file isolation between tenants in local filesystem
 * storage adapters.
 */
final class TenantStoragePathHelper
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
        private readonly string $pathSeparator = '/',
    ) {
    }

    /**
     * Prefixes a path with tenant-specific directory.
     *
     * @param string $path    The original path
     * @param bool   $useSlug Whether to use tenant slug instead of ID for the prefix
     *
     * @return string The prefixed path or original path if no tenant context
     */
    public function prefixPath(string $path, bool $useSlug = false): string
    {
        if (!$this->enabled) {
            return $path;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return $path;
        }

        $tenantIdentifier = $useSlug ? $tenant->getSlug() : (string) $tenant->getId();
        $prefix = 'tenants'.$this->pathSeparator.$tenantIdentifier;

        // Remove leading slash from path to avoid double slashes
        $cleanPath = ltrim($path, '/\\');

        return $prefix.$this->pathSeparator.$cleanPath;
    }

    /**
     * Gets the tenant-specific directory path.
     *
     * @param bool $useSlug Whether to use tenant slug instead of ID
     *
     * @return string|null The tenant directory path or null if no tenant context
     */
    public function getTenantDirectory(bool $useSlug = false): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return null;
        }

        $tenantIdentifier = $useSlug ? $tenant->getSlug() : (string) $tenant->getId();

        return 'tenants'.$this->pathSeparator.$tenantIdentifier;
    }

    /**
     * Removes tenant prefix from a path.
     *
     * @param string $path    The prefixed path
     * @param bool   $useSlug Whether the path was prefixed with slug instead of ID
     *
     * @return string The path without tenant prefix
     */
    public function removeTenantPrefix(string $path, bool $useSlug = false): string
    {
        if (!$this->enabled) {
            return $path;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return $path;
        }

        $tenantIdentifier = $useSlug ? $tenant->getSlug() : (string) $tenant->getId();
        $prefix = 'tenants'.$this->pathSeparator.$tenantIdentifier.$this->pathSeparator;

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * Checks if a path is tenant-prefixed.
     *
     * @param string $path    The path to check
     * @param bool   $useSlug Whether to check for slug-based prefix
     *
     * @return bool True if the path is tenant-prefixed
     */
    public function isTenantPrefixed(string $path, bool $useSlug = false): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return false;
        }

        $tenantIdentifier = $useSlug ? $tenant->getSlug() : (string) $tenant->getId();
        $prefix = 'tenants'.$this->pathSeparator.$tenantIdentifier.$this->pathSeparator;

        return str_starts_with($path, $prefix);
    }

    /**
     * Creates a tenant-aware file path for uploads.
     *
     * @param string $filename  The filename
     * @param string $directory Optional subdirectory within tenant space
     * @param bool   $useSlug   Whether to use tenant slug instead of ID
     *
     * @return string The full tenant-aware path
     */
    public function createUploadPath(string $filename, string $directory = '', bool $useSlug = false): string
    {
        $basePath = $directory ? trim($directory, '/\\').$this->pathSeparator.$filename : $filename;

        return $this->prefixPath($basePath, $useSlug);
    }

    /**
     * Gets the current tenant identifier used for prefixing.
     *
     * @param bool $useSlug Whether to return slug instead of ID
     *
     * @return string|null The tenant identifier or null if no tenant context
     */
    public function getCurrentTenantIdentifier(bool $useSlug = false): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return null;
        }

        return $useSlug ? $tenant->getSlug() : (string) $tenant->getId();
    }
}
