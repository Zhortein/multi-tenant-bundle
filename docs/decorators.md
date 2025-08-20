# Tenant-Aware Decorators

The Zhortein MultiTenantBundle provides several tenant-aware decorators that automatically isolate data and operations by tenant context. These decorators ensure that each tenant's data remains completely separate without requiring manual tenant filtering in your application code.

> 📖 **Navigation**: [← RLS Security](rls-security.md) | [Back to Documentation Index](index.md) | [FAQ →](faq.md)

## Overview

The bundle includes the following decorators:

- **Cache Decorators**: Automatically prefix cache keys with tenant identifiers
- **Logger Processor**: Adds tenant information to log records
- **Storage Path Helper**: Generates tenant-specific file paths

## Configuration

Enable and configure decorators in your bundle configuration:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
  decorators:
    cache:
      enabled: true
      services:
        - cache.app
        - cache.system
    logger:
      enabled: true
      channels:
        - app
        - security
    storage:
      enabled: true
      use_slug: false
      path_separator: '/'
```

## Cache Decorators

### PSR-6 Cache Decorator

The `TenantAwareCacheDecorator` automatically prefixes cache keys with the current tenant identifier, ensuring complete cache isolation between tenants.

#### Features

- Automatic key prefixing with tenant ID or slug
- Transparent operation - no code changes required
- Supports all PSR-6 cache operations
- Graceful fallback when no tenant context is available

#### Usage

```php
use Psr\Cache\CacheItemPoolInterface;

class UserService
{
    public function __construct(
        private CacheItemPoolInterface $cache
    ) {}

    public function getUserPreferences(int $userId): array
    {
        // Cache key is automatically prefixed with tenant ID
        $item = $this->cache->getItem("user_preferences_{$userId}");
        
        if (!$item->isHit()) {
            $preferences = $this->loadUserPreferences($userId);
            $item->set($preferences);
            $this->cache->save($item);
        }
        
        return $item->get();
    }
}
```

#### Key Prefixing

- **With tenant context**: `tenant:tenant-123:user_preferences_456`
- **Without tenant context**: `user_preferences_456`
- **Disabled**: `user_preferences_456`

### PSR-16 Simple Cache Decorator

The `TenantAwareSimpleCacheDecorator` provides the same tenant isolation for PSR-16 simple cache interfaces.

#### Usage

```php
use Psr\SimpleCache\CacheInterface;

class ConfigService
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    public function getApiConfiguration(): array
    {
        // Automatically isolated by tenant
        return $this->cache->get('api_config', []);
    }

    public function setApiConfiguration(array $config): void
    {
        $this->cache->set('api_config', $config, 3600);
    }
}
```

## Logger Processor

The `TenantLoggerProcessor` automatically adds tenant information to all log records, making it easy to filter and analyze logs by tenant.

### Features

- Adds `tenant_id` and `tenant_slug` to log records
- Works with any Monolog handler
- Preserves existing log context and extra data
- Configurable per channel

### Usage

The processor is automatically registered when enabled. No code changes are required:

```php
use Psr\Log\LoggerInterface;

class OrderService
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function processOrder(Order $order): void
    {
        // Log record will automatically include tenant information
        $this->logger->info('Processing order', [
            'order_id' => $order->getId(),
            'amount' => $order->getTotal(),
        ]);
        
        try {
            $this->paymentService->charge($order);
            $this->logger->info('Order processed successfully');
        } catch (PaymentException $e) {
            $this->logger->error('Payment failed', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId(),
            ]);
        }
    }
}
```

### Log Record Structure

```json
{
  "message": "Processing order",
  "context": {
    "order_id": 123,
    "amount": 99.99
  },
  "extra": {
    "tenant_id": "tenant-456",
    "tenant_slug": "acme-corp"
  }
}
```

## Storage Path Helper

The `TenantStoragePathHelper` generates tenant-specific file paths, ensuring that each tenant's files are stored in isolated directories.

### Features

- Automatic path prefixing with tenant ID or slug
- Configurable path separator
- Support for both tenant ID and slug-based paths
- Helper methods for path manipulation

### Configuration Options

```yaml
zhortein_multi_tenant:
  decorators:
    storage:
      enabled: true
      use_slug: true          # Use tenant slug instead of ID
      path_separator: '/'     # Path separator character
```

### Usage

```php
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;

class FileUploadService
{
    public function __construct(
        private TenantStoragePathHelper $pathHelper
    ) {}

    public function uploadFile(UploadedFile $file, string $directory): string
    {
        // Generate tenant-specific path
        $relativePath = $directory . '/' . $file->getClientOriginalName();
        $tenantPath = $this->pathHelper->prefixPath($relativePath);
        
        // tenantPath might be: "acme-corp/uploads/documents/file.pdf"
        
        $file->move($this->getStorageRoot() . '/' . dirname($tenantPath), basename($tenantPath));
        
        return $tenantPath;
    }

    public function getFileUrl(string $path): string
    {
        $tenantPath = $this->pathHelper->prefixPath($path);
        return $this->baseUrl . '/' . $tenantPath;
    }
}
```

### Path Examples

With tenant ID (`use_slug: false`):
- Input: `uploads/documents/file.pdf`
- Output: `tenant-123/uploads/documents/file.pdf`

With tenant slug (`use_slug: true`):
- Input: `uploads/documents/file.pdf`
- Output: `acme-corp/uploads/documents/file.pdf`

With custom separator (`path_separator: '_'`):
- Input: `uploads/documents/file.pdf`
- Output: `tenant-123_uploads/documents/file.pdf`

### Helper Methods

```php
// Get the current tenant prefix
$prefix = $pathHelper->getTenantPrefix(); // "tenant-123" or "acme-corp"

// Check if the helper is enabled
if ($pathHelper->isEnabled()) {
    // Apply tenant-specific logic
}

// Check if using slug-based paths
if ($pathHelper->usesSlug()) {
    // Handle slug-specific logic
}

// Get the configured path separator
$separator = $pathHelper->getPathSeparator(); // "/" or "_"
```

## Best Practices

### 1. Service Configuration

Register decorators for the appropriate services in your configuration:

```yaml
zhortein_multi_tenant:
  decorators:
    cache:
      enabled: true
      services:
        - cache.app          # Application cache
        - cache.system       # System cache
        # Don't decorate cache.global if it should be shared
    logger:
      enabled: true
      channels:
        - app               # Application logs
        - security          # Security logs
        # Don't include system channels that should be global
```

### 2. Conditional Decoration

Use the enabled flags to conditionally enable decorators:

```php
// In a service that might need tenant isolation
class CacheService
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private bool $tenantAware = false
    ) {}

    public function get(string $key): mixed
    {
        // The decorator handles tenant isolation automatically
        // No need for conditional logic here
        return $this->cache->getItem($key)->get();
    }
}
```

### 3. Testing

When testing tenant-aware services, ensure proper tenant context:

```php
class UserServiceTest extends TestCase
{
    private TenantContext $tenantContext;
    private UserService $userService;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        // ... setup service with decorated cache
    }

    public function testUserPreferencesIsolation(): void
    {
        $tenant1 = new Tenant('tenant-1', 'acme');
        $tenant2 = new Tenant('tenant-2', 'globex');

        // Set tenant 1 context
        $this->tenantContext->setCurrentTenant($tenant1);
        $this->userService->setUserPreferences(123, ['theme' => 'dark']);

        // Switch to tenant 2
        $this->tenantContext->setCurrentTenant($tenant2);
        $preferences = $this->userService->getUserPreferences(123);
        
        // Should not see tenant 1's preferences
        $this->assertEmpty($preferences);
    }
}
```

### 4. Performance Considerations

- Decorators add minimal overhead (single method call per operation)
- Cache key prefixing is done once per operation
- Logger processing happens only when logging
- Storage path prefixing is done on-demand

### 5. Debugging

Enable debug logging to see decorator behavior:

```yaml
# config/packages/monolog.yaml
monolog:
  handlers:
    main:
      type: stream
      path: '%kernel.logs_dir%/%kernel.environment%.log'
      level: debug
      channels: ['!event']
```

## Advanced Usage

### Custom Cache Strategies

You can implement custom cache isolation strategies by extending the decorators:

```php
class CustomTenantCacheDecorator extends TenantAwareCacheDecorator
{
    protected function prefixKey(string $key): string
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant || !$this->enabled) {
            return $key;
        }

        // Custom prefixing logic
        return sprintf('custom:%s:%s:%s', 
            $tenant->getId(), 
            $tenant->getRegion(), 
            $key
        );
    }
}
```

### Integration with File Storage

Combine the storage path helper with file storage services:

```php
class TenantFileStorage
{
    public function __construct(
        private TenantStoragePathHelper $pathHelper,
        private FilesystemInterface $filesystem
    ) {}

    public function store(string $path, string $content): void
    {
        $tenantPath = $this->pathHelper->prefixPath($path);
        $this->filesystem->write($tenantPath, $content);
    }

    public function read(string $path): string
    {
        $tenantPath = $this->pathHelper->prefixPath($path);
        return $this->filesystem->read($tenantPath);
    }
}
```

## Troubleshooting

### Common Issues

1. **Cache not isolated**: Ensure the cache service is properly decorated in configuration
2. **Logs missing tenant info**: Check that the logger processor is registered for the correct channels
3. **File paths not prefixed**: Verify that the storage helper is enabled and properly injected

### Debug Commands

Check decorator registration:

```bash
# List all services
php bin/console debug:container --tag=cache.pool

# Check specific service
php bin/console debug:container cache.app

# Verify logger processors
php bin/console debug:container --tag=monolog.processor
```

### Configuration Validation

The bundle validates decorator configuration at compile time. Invalid configurations will result in clear error messages during container compilation.