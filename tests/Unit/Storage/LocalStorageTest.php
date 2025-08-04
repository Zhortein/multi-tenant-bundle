<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;

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

        $this->assertSame('test-tenant/documents/test.txt', $result);
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

        $this->assertSame('test-tenant/uploads/uploaded.txt', $result);
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
        $this->assertSame('/uploads/test-tenant/documents/test.txt', $url);
    }

    public function testGetPath(): void
    {
        $path = $this->storage->getPath('documents/test.txt');
        $expected = $this->tempDir.'/test-tenant/documents/test.txt';
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

    public function testWithoutTenant(): void
    {
        // Create a new storage instance with null tenant context
        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->method('getTenant')->willReturn(null);

        $storage = new LocalStorage(
            $tenantContext,
            $this->tempDir,
            '/uploads'
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');
        $file = new File($tempFile);

        $result = $storage->upload($file, 'documents/test.txt');

        $this->assertSame('default/documents/test.txt', $result);
        $this->assertTrue($storage->exists('documents/test.txt'));

        unlink($tempFile);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
