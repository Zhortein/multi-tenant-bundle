<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Storage\S3Storage;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

/**
 * @covers \Zhortein\MultiTenantBundle\Storage\S3Storage
 * @covers \Zhortein\MultiTenantBundle\Storage\TenantStoragePathResolver
 */
final class S3StorageTest extends TestCase
{
    public function testObjectKeysUseTheExplicitTenantNamespace(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('default');
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = new S3Storage($context, 'bucket', 'region', 'https://objects.example');

        self::assertSame('tenants/default/nested/file.txt', $storage->getPath('nested/file.txt'));
        self::assertSame(
            'https://objects.example/tenants/default/nested/file.txt',
            $storage->getUrl('nested/file.txt')
        );
    }

    public function testEveryPathOperationFailsClosedWithoutTenant(): void
    {
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn(null);
        $storage = new S3Storage($context, 'bucket', 'region', 'https://objects.example');

        foreach (['getPath', 'getUrl', 'exists', 'delete', 'listFiles'] as $operation) {
            try {
                $storage->{$operation}('nested/file.txt');
                self::fail(sprintf('%s must require an active tenant.', $operation));
            } catch (TenantStorageException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMaliciousPathsAreRejectedBeforeBackendAccess(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('tenant-a');
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = new S3Storage($context, 'bucket', 'region', 'https://objects.example');

        foreach (['../escape.txt', '%2e%2e/escape.txt', 'nested\\..\\escape.txt'] as $path) {
            try {
                $storage->exists($path);
                self::fail(sprintf('The object key %s must be rejected.', $path));
            } catch (TenantStorageException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnsafeTenantIdentifierIsRejected(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('../global');
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = new S3Storage($context, 'bucket', 'region', 'https://objects.example');

        $this->expectException(TenantStorageException::class);
        $storage->getPath('file.txt');
    }

    public function testEmptyObjectPathIsRejectedOutsideRootListing(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('tenant-a');
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = new S3Storage($context, 'bucket', 'region', 'https://objects.example');

        $this->expectException(TenantStorageException::class);
        $storage->getPath('');
    }
}
