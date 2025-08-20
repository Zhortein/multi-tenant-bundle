# Tenant-Aware Storage Usage Examples

> 📖 **Navigation**: [← Messenger Usage](messenger-usage.md) | [Back to Documentation Index](../index.md) | [Test Kit Usage →](test-kit-usage.md)

## Basic Configuration

### 1. Service Configuration

```yaml
# config/services.yaml
services:
    # Local storage implementation
    Zhortein\MultiTenantBundle\Storage\LocalStorage:
        arguments:
            $baseDirectory: '%kernel.project_dir%/var/tenant_storage'
            $baseUrl: '/tenant-files'

    # S3 storage implementation
    Zhortein\MultiTenantBundle\Storage\S3Storage:
        arguments:
            $bucket: '%env(AWS_S3_BUCKET)%'
            $region: '%env(AWS_S3_REGION)%'
            $baseUrl: '%env(AWS_S3_BASE_URL)%'
            $accessKey: '%env(AWS_ACCESS_KEY_ID)%'
            $secretKey: '%env(AWS_SECRET_ACCESS_KEY)%'

    # Alias for the storage interface
    Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface: '@Zhortein\MultiTenantBundle\Storage\LocalStorage'
```

### 2. Environment Configuration

```bash
# .env
AWS_S3_BUCKET=my-tenant-bucket
AWS_S3_REGION=us-east-1
AWS_S3_BASE_URL=https://my-tenant-bucket.s3.amazonaws.com
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
```

## Usage Examples

### 1. Basic File Upload

```php
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

class FileUploadService
{
    public function __construct(
        private TenantFileStorageInterface $storage
    ) {}

    public function uploadUserAvatar(UploadedFile $file, int $userId): string
    {
        // File will be stored in tenant-specific directory
        $path = sprintf('avatars/user_%d.%s', $userId, $file->getClientOriginalExtension());
        
        return $this->storage->uploadFile($file, $path);
    }

    public function uploadDocument(UploadedFile $file, string $category): string
    {
        $filename = sprintf(
            '%s_%s.%s',
            $category,
            uniqid(),
            $file->getClientOriginalExtension()
        );
        
        $path = sprintf('documents/%s/%s', $category, $filename);
        
        return $this->storage->uploadFile($file, $path);
    }
}
```

### 2. File Management Service

```php
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantFileManager
{
    public function __construct(
        private TenantFileStorageInterface $storage,
        private TenantContextInterface $tenantContext
    ) {}

    public function getFileInfo(string $path): array
    {
        $tenant = $this->tenantContext->getTenant();
        
        return [
            'exists' => $this->storage->exists($path),
            'url' => $this->storage->getUrl($path),
            'full_path' => $this->storage->getPath($path),
            'tenant' => $tenant?->getSlug(),
        ];
    }

    public function listTenantFiles(string $directory = ''): array
    {
        return $this->storage->listFiles($directory);
    }

    public function deleteFile(string $path): bool
    {
        if (!$this->storage->exists($path)) {
            return false;
        }

        $this->storage->delete($path);
        return true;
    }

    public function moveFile(string $sourcePath, string $destinationPath): bool
    {
        if (!$this->storage->exists($sourcePath)) {
            return false;
        }

        // For local storage, we can implement move operation
        // For cloud storage, this would be copy + delete
        $fullSourcePath = $this->storage->getPath($sourcePath);
        $fullDestinationPath = $this->storage->getPath($destinationPath);

        if (rename($fullSourcePath, $fullDestinationPath)) {
            return true;
        }

        return false;
    }
}
```

### 3. Integration with VichUploaderBundle

```php
// src/Entity/TenantDocument.php
use Doctrine\ORM\Mapping as ORM;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityTrait;

#[ORM\Entity]
#[Vich\Uploadable]
class TenantDocument implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[Vich\UploadableField(mapping: 'tenant_documents', fileNameProperty: 'filename')]
    private ?File $file = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Getters and setters...

    public function setFile(?File $file = null): void
    {
        $this->file = $file;

        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }
}

// config/packages/vich_uploader.yaml
vich_uploader:
    db_driver: orm
    mappings:
        tenant_documents:
            uri_prefix: /tenant-files
            upload_destination: '%kernel.project_dir%/var/tenant_storage'
            namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
            directory_namer: App\Service\TenantDirectoryNamer

// src/Service/TenantDirectoryNamer.php
use Vich\UploaderBundle\Naming\DirectoryNamerInterface;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantDirectoryNamer implements DirectoryNamerInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext
    ) {}

    public function directoryName($object, PropertyMapping $mapping): string
    {
        $tenant = $this->tenantContext->getTenant();
        $tenantSlug = $tenant?->getSlug() ?? 'default';
        
        return sprintf('%s/documents', $tenantSlug);
    }
}
```

### 4. Advanced S3 Storage with CDN

```php
use Zhortein\MultiTenantBundle\Storage\S3Storage;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class AdvancedS3Storage extends S3Storage
{
    public function __construct(
        TenantContextInterface $tenantContext,
        private TenantSettingsManager $settingsManager,
        string $bucket,
        string $region,
        string $baseUrl,
        ?string $accessKey = null,
        ?string $secretKey = null
    ) {
        parent::__construct($tenantContext, $bucket, $region, $baseUrl, $accessKey, $secretKey);
    }

    public function getUrl(string $path): string
    {
        // Check if tenant has custom CDN configuration
        $cdnUrl = $this->settingsManager->get('cdn_base_url');
        
        if ($cdnUrl) {
            $tenantPath = $this->getTenantPath($path);
            return rtrim($cdnUrl, '/') . '/' . ltrim($tenantPath, '/');
        }

        return parent::getUrl($path);
    }

    public function uploadWithMetadata(File $file, string $path, array $metadata = []): string
    {
        $tenant = $this->tenantContext->getTenant();
        
        // Add tenant-specific metadata
        $metadata['tenant_id'] = $tenant?->getId();
        $metadata['tenant_slug'] = $tenant?->getSlug();
        $metadata['uploaded_at'] = (new \DateTime())->format('c');
        
        // Implementation would use AWS SDK to upload with metadata
        return $this->upload($file, $path);
    }
}
```

### 5. File Processing Service

```php
use Symfony\Component\HttpFoundation\File\File;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

class TenantFileProcessor
{
    public function __construct(
        private TenantFileStorageInterface $storage
    ) {}

    public function processImage(string $imagePath, array $sizes = []): array
    {
        $processedFiles = [];
        
        if (!$this->storage->exists($imagePath)) {
            throw new \InvalidArgumentException('Image file not found: ' . $imagePath);
        }

        $fullPath = $this->storage->getPath($imagePath);
        $originalImage = new File($fullPath);
        
        foreach ($sizes as $size => $dimensions) {
            $resizedPath = $this->generateResizedPath($imagePath, $size);
            $resizedImage = $this->resizeImage($originalImage, $dimensions);
            
            $processedFiles[$size] = $this->storage->upload($resizedImage, $resizedPath);
        }

        return $processedFiles;
    }

    private function generateResizedPath(string $originalPath, string $size): string
    {
        $pathInfo = pathinfo($originalPath);
        return sprintf(
            '%s/%s_%s.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $size,
            $pathInfo['extension']
        );
    }

    private function resizeImage(File $image, array $dimensions): File
    {
        // Implementation would use image processing library like Imagine
        // This is a simplified example
        return $image;
    }

    public function generateThumbnail(string $imagePath, int $width = 150, int $height = 150): string
    {
        $thumbnailPath = $this->generateResizedPath($imagePath, 'thumb');
        
        if ($this->storage->exists($thumbnailPath)) {
            return $thumbnailPath;
        }

        $fullPath = $this->storage->getPath($imagePath);
        $originalImage = new File($fullPath);
        
        // Generate thumbnail
        $thumbnail = $this->resizeImage($originalImage, ['width' => $width, 'height' => $height]);
        
        return $this->storage->upload($thumbnail, $thumbnailPath);
    }
}
```

## Controller Examples

### 1. File Upload Controller

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

#[Route('/api/files')]
class FileController extends AbstractController
{
    public function __construct(
        private TenantFileStorageInterface $storage
    ) {}

    #[Route('/upload', name: 'file_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        
        if (!$uploadedFile) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        try {
            $category = $request->request->get('category', 'general');
            $filename = sprintf(
                '%s/%s_%s.%s',
                $category,
                uniqid(),
                $uploadedFile->getClientOriginalName(),
                $uploadedFile->getClientOriginalExtension()
            );

            $path = $this->storage->uploadFile($uploadedFile, $filename);
            $url = $this->storage->getUrl($path);

            return new JsonResponse([
                'success' => true,
                'path' => $path,
                'url' => $url,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/list', name: 'file_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $directory = $request->query->get('directory', '');
        $files = $this->storage->listFiles($directory);

        $fileList = array_map(function ($file) {
            return [
                'path' => $file,
                'url' => $this->storage->getUrl($file),
                'exists' => $this->storage->exists($file),
            ];
        }, $files);

        return new JsonResponse($fileList);
    }

    #[Route('/delete/{path}', name: 'file_delete', methods: ['DELETE'])]
    public function delete(string $path): JsonResponse
    {
        try {
            if (!$this->storage->exists($path)) {
                return new JsonResponse(['error' => 'File not found'], 404);
            }

            $this->storage->delete($path);

            return new JsonResponse(['success' => true]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
```

### 2. Image Gallery Controller

```php
#[Route('/gallery')]
class GalleryController extends AbstractController
{
    public function __construct(
        private TenantFileStorageInterface $storage,
        private TenantFileProcessor $processor
    ) {}

    #[Route('/upload-image', name: 'gallery_upload', methods: ['POST'])]
    public function uploadImage(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('image');
        
        if (!$uploadedFile || !str_starts_with($uploadedFile->getMimeType(), 'image/')) {
            return new JsonResponse(['error' => 'Invalid image file'], 400);
        }

        try {
            $filename = sprintf('gallery/%s.%s', uniqid(), $uploadedFile->getClientOriginalExtension());
            $path = $this->storage->uploadFile($uploadedFile, $filename);

            // Generate different sizes
            $sizes = [
                'thumb' => ['width' => 150, 'height' => 150],
                'medium' => ['width' => 500, 'height' => 500],
                'large' => ['width' => 1200, 'height' => 1200],
            ];

            $processedImages = $this->processor->processImage($path, $sizes);

            return new JsonResponse([
                'success' => true,
                'original' => [
                    'path' => $path,
                    'url' => $this->storage->getUrl($path),
                ],
                'sizes' => array_map(fn($sizePath) => [
                    'path' => $sizePath,
                    'url' => $this->storage->getUrl($sizePath),
                ], $processedImages),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
```

## Testing

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Storage\LocalStorage;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class LocalStorageTest extends TestCase
{
    private string $tempDir;
    private LocalStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tenant_storage_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenantContext->method('getTenant')->willReturn($tenant);

        $this->storage = new LocalStorage($tenantContext, $this->tempDir, '/files');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testUploadFile(): void
    {
        $content = 'test file content';
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, $content);

        $uploadedFile = new UploadedFile($tempFile, 'test.txt', 'text/plain', null, true);
        $path = $this->storage->uploadFile($uploadedFile, 'documents/test.txt');

        $this->assertEquals('documents/test.txt', $path);
        $this->assertTrue($this->storage->exists($path));
        $this->assertEquals($content, file_get_contents($this->storage->getPath($path)));
    }

    public function testGetUrl(): void
    {
        $path = 'documents/test.txt';
        $url = $this->storage->getUrl($path);
        
        $this->assertEquals('/files/test-tenant/documents/test.txt', $url);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

## Best Practices

### 1. File Naming Conventions

```php
class FileNamingService
{
    public function generateSecureFilename(string $originalName, string $category = 'general'): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        $safeName = trim($safeName, '_');
        
        return sprintf(
            '%s/%s_%s_%s.%s',
            $category,
            date('Y/m/d'),
            $safeName,
            uniqid(),
            $extension
        );
    }
}
```

### 2. File Validation

```php
class FileValidator
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function validate(UploadedFile $file): array
    {
        $errors = [];

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'File size exceeds maximum allowed size';
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            $errors[] = 'File type not allowed';
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error: ' . $file->getErrorMessage();
        }

        return $errors;
    }
}
```

### 3. Storage Abstraction

```php
// Use factory pattern for different storage types
class TenantStorageFactory
{
    public function createStorage(string $type, array $config): TenantFileStorageInterface
    {
        return match ($type) {
            'local' => new LocalStorage(
                $this->tenantContext,
                $config['base_directory'],
                $config['base_url']
            ),
            's3' => new S3Storage(
                $this->tenantContext,
                $config['bucket'],
                $config['region'],
                $config['base_url'],
                $config['access_key'],
                $config['secret_key']
            ),
            default => throw new \InvalidArgumentException('Unknown storage type: ' . $type),
        };
    }
}
```

## Configuration Reference

### Local Storage Configuration

```yaml
services:
    Zhortein\MultiTenantBundle\Storage\LocalStorage:
        arguments:
            $baseDirectory: '%kernel.project_dir%/var/tenant_storage'
            $baseUrl: '/tenant-files'
```

### S3 Storage Configuration

```yaml
services:
    Zhortein\MultiTenantBundle\Storage\S3Storage:
        arguments:
            $bucket: '%env(AWS_S3_BUCKET)%'
            $region: '%env(AWS_S3_REGION)%'
            $baseUrl: '%env(AWS_S3_BASE_URL)%'
            $accessKey: '%env(AWS_ACCESS_KEY_ID)%'
            $secretKey: '%env(AWS_SECRET_ACCESS_KEY)%'
```

### Directory Structure

```
var/tenant_storage/
├── tenant-1/
│   ├── documents/
│   ├── images/
│   └── uploads/
├── tenant-2/
│   ├── documents/
│   ├── images/
│   └── uploads/
└── default/
    ├── documents/
    ├── images/
    └── uploads/
```