<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Storage;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final readonly class TenantStoragePathResolver
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function tenantNamespace(bool $useSlug = true): string
    {
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            throw new TenantStorageException('Tenant-aware storage requires an active tenant context. Use an explicit global storage service for non-tenant files.');
        }

        $identifier = $useSlug ? $tenant->getSlug() : (string) $tenant->getId();
        $this->assertSafeComponent($identifier, 'tenant identifier');

        return 'tenants/'.$identifier;
    }

    public function resolve(string $path, bool $allowEmpty = false, bool $useSlug = true): string
    {
        $normalizedPath = $this->validateRelativePath($path, $allowEmpty);
        $namespace = $this->tenantNamespace($useSlug);

        return '' === $normalizedPath ? $namespace : $namespace.'/'.$normalizedPath;
    }

    public function validateRelativePath(string $path, bool $allowEmpty = false): string
    {
        if (str_contains($path, "\0")) {
            throw new TenantStorageException('Storage paths must not contain null bytes.');
        }

        if ('' === $path) {
            if ($allowEmpty) {
                return '';
            }

            throw new TenantStorageException('Storage paths must not be empty.');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new TenantStorageException('Storage paths must be relative to the active tenant namespace.');
        }

        if (str_contains($path, '\\') || str_contains($path, '//') || str_contains($path, '%')) {
            throw new TenantStorageException('Storage paths must use unambiguous forward-slash components and must not contain encoded separators.');
        }

        $components = explode('/', $path);
        foreach ($components as $component) {
            $this->assertSafeComponent($component, 'path component');
        }

        return implode('/', $components);
    }

    private function assertSafeComponent(string $component, string $label): void
    {
        if ('' === $component || '.' === $component || '..' === $component) {
            throw new TenantStorageException(sprintf('The %s must not be empty or a dot segment.', $label));
        }

        if (1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $component)) {
            throw new TenantStorageException(sprintf('The %s contains unsafe characters.', $label));
        }
    }
}
