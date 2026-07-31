<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

/**
 * @covers \Zhortein\MultiTenantBundle\Storage\LocalStorage
 */
final class LocalStorageTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantInterface $tenant;
    private LocalStorage $storage;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tempDir = sys_get_temp_dir().'/tenant_storage_test_'.uniqid();

        $this->storage = new LocalStorage(
            $this->tenantContext,
            $this->tempDir,
            '/uploads'
        );

        $this->tenant->method('getSlug')->willReturn('test-tenant');
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function testUpload(): void
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');
        $file = new File($tempFile);

        $result = $this->storage->upload($file, 'documents/test.txt');

        $this->assertSame('tenants/test-tenant/documents/test.txt', $result);
        $this->assertTrue($this->storage->exists('documents/test.txt'));
        $this->assertSame('test content', file_get_contents($this->storage->getPath('documents/test.txt')));

        unlink($tempFile);
    }

    public function testUploadFile(): void
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'uploaded content');

        $uploadedFile = new UploadedFile(
            $tempFile,
            'original.txt',
            'text/plain',
            null,
            true
        );

        $result = $this->storage->uploadFile($uploadedFile, 'uploads/uploaded.txt');

        $this->assertSame('tenants/test-tenant/uploads/uploaded.txt', $result);
        $this->assertTrue($this->storage->exists('uploads/uploaded.txt'));
    }

    public function testDelete(): void
    {
        // Create a file first
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');
        $file = new File($tempFile);

        $this->storage->upload($file, 'documents/test.txt');
        $this->assertTrue($this->storage->exists('documents/test.txt'));

        $this->storage->delete('documents/test.txt');
        $this->assertFalse($this->storage->exists('documents/test.txt'));

        unlink($tempFile);
    }

    public function testGetUrl(): void
    {
        $url = $this->storage->getUrl('documents/test.txt');
        $this->assertSame('/uploads/tenants/test-tenant/documents/test.txt', $url);
    }

    public function testGetPath(): void
    {
        $path = $this->storage->getPath('documents/test.txt');
        $expected = $this->tempDir.'/tenants/test-tenant/documents/test.txt';
        $this->assertSame($expected, $path);
    }

    public function testListFiles(): void
    {
        // Create some test files
        $tempFile1 = tempnam(sys_get_temp_dir(), 'test1');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'test2');
        file_put_contents($tempFile1, 'content1');
        file_put_contents($tempFile2, 'content2');

        $this->storage->upload(new File($tempFile1), 'docs/file1.txt');
        $this->storage->upload(new File($tempFile2), 'docs/file2.txt');

        $files = $this->storage->listFiles('docs');

        $this->assertCount(2, $files);
        $this->assertContains('docs/file1.txt', $files);
        $this->assertContains('docs/file2.txt', $files);

        unlink($tempFile1);
        unlink($tempFile2);
    }

    public function testWithoutTenantFailsClosed(): void
    {
        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->method('getTenant')->willReturn(null);
        $storage = new LocalStorage($tenantContext, $this->tempDir, '/uploads');

        $this->expectException(TenantStorageException::class);
        $this->expectExceptionMessage('requires an active tenant context');

        $storage->exists('documents/test.txt');
    }

    public function testTenantDefaultUsesAnExplicitTenantNamespace(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('default');
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = new LocalStorage($context, $this->tempDir, '/uploads');

        self::assertSame($this->tempDir.'/tenants/default/file.txt', $storage->getPath('file.txt'));
        self::assertNotSame($this->tempDir.'/default/file.txt', $storage->getPath('file.txt'));
    }

    public function testMaliciousPathsAreRejectedByEveryPathBasedOperation(): void
    {
        $paths = [
            '/absolute.txt',
            '../escape.txt',
            'docs/../escape.txt',
            './file.txt',
            'docs//file.txt',
            'docs\\..\\file.txt',
            "docs/evil\0.txt",
            '%2e%2e/escape.txt',
            'C:/windows.txt',
        ];

        foreach ($paths as $path) {
            foreach (['getPath', 'getUrl', 'exists', 'delete', 'listFiles'] as $operation) {
                try {
                    $this->storage->{$operation}($path);
                    self::fail(sprintf('%s should reject malicious path %s.', $operation, bin2hex($path)));
                } catch (TenantStorageException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testTenantRotationCannotReadOverwriteListOrDeleteAnotherTenantFiles(): void
    {
        $tenantA = $this->createMock(TenantInterface::class);
        $tenantA->method('getSlug')->willReturn('tenant-a');
        $tenantB = $this->createMock(TenantInterface::class);
        $tenantB->method('getSlug')->willReturn('tenant-b');
        $activeTenant = $tenantA;
        $context = $this->createMock(TenantContextInterface::class);
        $context->method('getTenant')->willReturnCallback(static function () use (&$activeTenant): TenantInterface {
            return $activeTenant;
        });
        $storage = new LocalStorage($context, $this->tempDir, '/uploads');

        $sourceA = tempnam(sys_get_temp_dir(), 'tenant-a');
        $sourceB = tempnam(sys_get_temp_dir(), 'tenant-b');
        file_put_contents($sourceA, 'tenant A');
        file_put_contents($sourceB, 'tenant B');

        try {
            $storage->upload(new File($sourceA), 'shared/file.txt');
            $activeTenant = $tenantB;

            self::assertFalse($storage->exists('shared/file.txt'));
            self::assertSame([], $storage->listFiles('shared'));
            $storage->delete('shared/file.txt');
            $storage->upload(new File($sourceB), 'shared/file.txt');
            self::assertSame('tenant B', file_get_contents($storage->getPath('shared/file.txt')));

            $activeTenant = $tenantA;
            self::assertTrue($storage->exists('shared/file.txt'));
            self::assertSame('tenant A', file_get_contents($storage->getPath('shared/file.txt')));
            self::assertSame(['shared/file.txt'], $storage->listFiles('shared'));
        } finally {
            unlink($sourceA);
            unlink($sourceB);
        }
    }

    public function testSymbolicLinkEscapeIsRejected(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'outside');
        $tenantDirectory = $this->tempDir.'/tenants/test-tenant';
        mkdir($tenantDirectory, 0777, true);
        symlink($outside, $tenantDirectory.'/escape.txt');

        try {
            $this->expectException(TenantStorageException::class);
            $this->storage->exists('escape.txt');
        } finally {
            unlink($outside);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSymbolicLinkInBasePathIsRejected(): void
    {
        $outsideDirectory = sys_get_temp_dir().'/tenant_storage_outside_'.uniqid();
        mkdir($outsideDirectory);
        mkdir($this->tempDir);
        symlink($outsideDirectory, $this->tempDir.'/linked');
        $storage = new LocalStorage(
            $this->tenantContext,
            $this->tempDir.'/linked/storage',
            '/uploads'
        );

        try {
            $this->expectException(TenantStorageException::class);
            $storage->getPath('file.txt');
        } finally {
            unlink($this->tempDir.'/linked');
            rmdir($outsideDirectory);
        }
    }
}
