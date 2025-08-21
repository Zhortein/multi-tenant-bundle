<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper
 */
final class TenantStoragePathHelperTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantInterface $tenant;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getId')->willReturn('tenant-123');
        $this->tenant->method('getSlug')->willReturn('test-tenant');
    }

    public function testPrefixPathWithTenantId(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->prefixPath('uploads/file.txt');

        $this->assertSame('tenants/tenant-123/uploads/file.txt', $result);
    }

    public function testPrefixPathWithTenantSlug(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->prefixPath('uploads/file.txt', true);

        $this->assertSame('tenants/test-tenant/uploads/file.txt', $result);
    }

    public function testPrefixPathWithoutTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->prefixPath('uploads/file.txt');

        $this->assertSame('uploads/file.txt', $result);
    }

    public function testPrefixPathWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext, false);
        $result = $helper->prefixPath('uploads/file.txt');

        $this->assertSame('uploads/file.txt', $result);
    }

    public function testPrefixPathWithCustomSeparator(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext, true, '_');
        $result = $helper->prefixPath('uploads/file.txt');

        $this->assertSame('tenants_tenant-123_uploads/file.txt', $result);
    }

    public function testPrefixPathWithEmptyPath(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->prefixPath('');

        $this->assertSame('tenants/tenant-123/', $result);
    }

    public function testPrefixPathWithLeadingSlash(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->prefixPath('/uploads/file.txt');

        $this->assertSame('tenants/tenant-123/uploads/file.txt', $result);
    }

    public function testGetTenantDirectory(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->getTenantDirectory();

        $this->assertSame('tenants/tenant-123', $result);
    }

    public function testGetTenantDirectoryWithSlug(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->getTenantDirectory(true);

        $this->assertSame('tenants/test-tenant', $result);
    }

    public function testGetTenantDirectoryWithoutTenant(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->getTenantDirectory();

        $this->assertNull($result);
    }

    public function testGetTenantDirectoryWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext, false);
        $result = $helper->getTenantDirectory();

        $this->assertNull($result);
    }

    public function testRemoveTenantPrefix(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->removeTenantPrefix('tenants/tenant-123/uploads/file.txt');

        $this->assertSame('uploads/file.txt', $result);
    }

    public function testRemoveTenantPrefixWithSlug(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->removeTenantPrefix('tenants/test-tenant/uploads/file.txt', true);

        $this->assertSame('uploads/file.txt', $result);
    }

    public function testRemoveTenantPrefixWithoutPrefix(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->removeTenantPrefix('uploads/file.txt');

        $this->assertSame('uploads/file.txt', $result);
    }

    public function testIsTenantPrefixed(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);

        $this->assertTrue($helper->isTenantPrefixed('tenants/tenant-123/uploads/file.txt'));
        $this->assertFalse($helper->isTenantPrefixed('uploads/file.txt'));
    }

    public function testIsTenantPrefixedWithSlug(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);

        $this->assertTrue($helper->isTenantPrefixed('tenants/test-tenant/uploads/file.txt', true));
        $this->assertFalse($helper->isTenantPrefixed('tenants/tenant-123/uploads/file.txt', true));
    }

    public function testCreateUploadPath(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->createUploadPath('file.txt', 'documents');

        $this->assertSame('tenants/tenant-123/documents/file.txt', $result);
    }

    public function testCreateUploadPathWithoutDirectory(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->createUploadPath('file.txt');

        $this->assertSame('tenants/tenant-123/file.txt', $result);
    }

    public function testGetCurrentTenantIdentifier(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext);

        $this->assertSame('tenant-123', $helper->getCurrentTenantIdentifier());
        $this->assertSame('test-tenant', $helper->getCurrentTenantIdentifier(true));
    }

    public function testGetCurrentTenantIdentifierWithoutTenant(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $helper = new TenantStoragePathHelper($this->tenantContext);
        $result = $helper->getCurrentTenantIdentifier();

        $this->assertNull($result);
    }

    public function testGetCurrentTenantIdentifierWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $helper = new TenantStoragePathHelper($this->tenantContext, false);
        $result = $helper->getCurrentTenantIdentifier();

        $this->assertNull($result);
    }
}
