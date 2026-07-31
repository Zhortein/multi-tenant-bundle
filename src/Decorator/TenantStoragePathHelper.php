<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;
use Zhortein\MultiTenantBundle\Storage\TenantStoragePathResolver;

/**
 * Helper for prefixing storage paths with tenant information.
 *
 * This helper provides methods to prefix file paths with tenant-specific
 * directories, ensuring file isolation between tenants in local filesystem
 * storage adapters.
 */
final class TenantStoragePathHelper
{
    private readonly TenantStoragePathResolver $pathResolver;

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
        private readonly string $pathSeparator = '/',
    ) {
        if ('/' !== $pathSeparator) {
            throw new TenantStorageException('Tenant storage paths only support the forward-slash separator.');
        }

        $this->pathResolver = new TenantStoragePathResolver($tenantContext);
    }

    /**
     * Prefixes a path with the active tenant namespace.
     */
    public function prefixPath(string $path, bool $useSlug = false): string
    {
        if (!$this->enabled) {
            return $path;
        }

        return $this->pathResolver->resolve($path, true, $useSlug);
    }

    /**
     * Gets the active tenant namespace, or null when this helper is disabled.
     */
    public function getTenantDirectory(bool $useSlug = false): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->pathResolver->tenantNamespace($useSlug);
    }

    /**
     * Removes the active tenant prefix from a validated path.
     */
    public function removeTenantPrefix(string $path, bool $useSlug = false): string
    {
        if (!$this->enabled) {
            return $path;
        }

        $validatedPath = $this->pathResolver->validateRelativePath($path);
        $prefix = $this->pathResolver->tenantNamespace($useSlug).'/';

        if (str_starts_with($validatedPath, $prefix)) {
            return substr($validatedPath, strlen($prefix));
        }

        return $validatedPath;
    }

    /**
     * Checks whether a validated path belongs to the active tenant namespace.
     */
    public function isTenantPrefixed(string $path, bool $useSlug = false): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $validatedPath = $this->pathResolver->validateRelativePath($path);
        $prefix = $this->pathResolver->tenantNamespace($useSlug).'/';

        return str_starts_with($validatedPath, $prefix);
    }

    /**
     * Creates a tenant-aware upload path.
     */
    public function createUploadPath(string $filename, string $directory = '', bool $useSlug = false): string
    {
        $basePath = '' !== $directory ? $directory.$this->pathSeparator.$filename : $filename;

        return $this->prefixPath($basePath, $useSlug);
    }

    /**
     * Gets the active tenant identifier, or null when this helper is disabled.
     */
    public function getCurrentTenantIdentifier(bool $useSlug = false): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $namespace = $this->pathResolver->tenantNamespace($useSlug);

        return substr($namespace, strlen('tenants/'));
    }
}
