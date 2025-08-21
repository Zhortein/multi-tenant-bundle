# Tenant Settings

The tenant settings system provides a flexible way to store and retrieve tenant-specific configuration values. It supports hierarchical settings with fallback rules, caching for performance, and type-safe value handling.

> 📖 **Navigation**: [← Tenant Context](tenant-context.md) | [Back to Documentation Index](index.md) | [Mailer →](mailer.md)

## Overview

The tenant settings system consists of:

- **TenantSettingsManager**: Main service for managing settings
- **Hierarchical Fallbacks**: Bundle config → Default values → Null
- **Caching Layer**: Configurable caching for performance
- **Type Safety**: Automatic type conversion and validation

## Configuration

### Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Cache configuration for settings
    cache:
        enabled: true
        pool: 'cache.app'
        ttl: 3600 # 1 hour
    
    # Service fallback configurations
    mailer:
        enabled: true
        fallback_dsn: 'smtp://localhost:1025'
        fallback_from: 'noreply@example.com'
        fallback_sender: 'Default Sender'
    
    messenger:
        enabled: true
        fallback_dsn: 'sync://'
        fallback_bus: 'messenger.bus.default'
    
    storage:
        enabled: true
        type: 'local'
        base_path: '%kernel.project_dir%/var/tenant_storage'
        base_url: '/tenant-files'
```

### Cache Pool Configuration

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            tenant_settings_cache:
                adapter: cache.adapter.redis
                default_lifetime: 3600
                
# Use custom cache pool
zhortein_multi_tenant:
    cache:
        pool: 'tenant_settings_cache'
```

## Basic Usage

### Injecting the Settings Manager

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class ConfigurationService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
    ) {}

    public function getAppConfiguration(): array
    {
        return [
            'theme' => $this->settingsManager->get('theme', 'default'),
            'logo_url' => $this->settingsManager->get('logo_url'),
            'company_name' => $this->settingsManager->get('company_name', 'My Company'),
            'timezone' => $this->settingsManager->get('timezone', 'UTC'),
            'language' => $this->settingsManager->get('language', 'en'),
        ];
    }
}
```

### Setting Values

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings')]
    public function index(TenantSettingsManager $settingsManager): Response
    {
        $settings = [
            'theme' => $settingsManager->get('theme', 'default'),
            'notifications_enabled' => $settingsManager->get('notifications_enabled', true),
            'max_users' => $settingsManager->get('max_users', 10),
            'features' => $settingsManager->get('features', []),
        ];

        return $this->render('settings/index.html.twig', [
            'settings' => $settings,
        ]);
    }

    #[Route('/settings/update', name: 'settings_update', methods: ['POST'])]
    public function update(Request $request, TenantSettingsManager $settingsManager): Response
    {
        $theme = $request->request->get('theme');
        $notificationsEnabled = $request->request->getBoolean('notifications_enabled');
        $maxUsers = $request->request->getInt('max_users');

        // Set individual settings
        $settingsManager->set('theme', $theme);
        $settingsManager->set('notifications_enabled', $notificationsEnabled);
        $settingsManager->set('max_users', $maxUsers);

        // Set multiple settings at once
        $settingsManager->setMultiple([
            'updated_at' => new \DateTimeImmutable(),
            'updated_by' => $this->getUser()->getId(),
        ]);

        $this->addFlash('success', 'Settings updated successfully');

        return $this->redirectToRoute('settings');
    }
}
```

## Advanced Usage

### Type-Safe Settings

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TypedSettingsService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
    ) {}

    public function getStringSetting(string $key, ?string $default = null): ?string
    {
        $value = $this->settingsManager->get($key, $default);
        return is_string($value) ? $value : $default;
    }

    public function getIntSetting(string $key, ?int $default = null): ?int
    {
        $value = $this->settingsManager->get($key, $default);
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function getBoolSetting(string $key, ?bool $default = null): ?bool
    {
        $value = $this->settingsManager->get($key, $default);
        return is_bool($value) ? $value : (is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $default);
    }

    public function getArraySetting(string $key, array $default = []): array
    {
        $value = $this->settingsManager->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    public function getDateTimeSetting(string $key, ?\DateTimeInterface $default = null): ?\DateTimeInterface
    {
        $value = $this->settingsManager->get($key, $default);
        
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        
        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return $default;
            }
        }
        
        return $default;
    }
}
```

### Settings with Validation

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ValidatedSettingsService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
        private ValidatorInterface $validator,
    ) {}

    public function setEmailSettings(string $fromAddress, string $senderName, string $smtpHost): void
    {
        // Validate email address
        $emailConstraint = new Assert\Email();
        $violations = $this->validator->validate($fromAddress, $emailConstraint);
        
        if (count($violations) > 0) {
            throw new \InvalidArgumentException('Invalid email address: ' . $fromAddress);
        }

        // Validate sender name
        if (empty(trim($senderName))) {
            throw new \InvalidArgumentException('Sender name cannot be empty');
        }

        // Validate SMTP host
        $urlConstraint = new Assert\Url();
        $hostUrl = 'smtp://' . $smtpHost;
        $violations = $this->validator->validate($hostUrl, $urlConstraint);
        
        if (count($violations) > 0) {
            throw new \InvalidArgumentException('Invalid SMTP host: ' . $smtpHost);
        }

        // Set validated settings
        $this->settingsManager->setMultiple([
            'email_from' => $fromAddress,
            'email_sender' => $senderName,
            'smtp_host' => $smtpHost,
            'email_settings_updated_at' => new \DateTimeImmutable(),
        ]);
    }

    public function setTheme(string $theme): void
    {
        $allowedThemes = ['default', 'dark', 'light', 'blue', 'green'];
        
        if (!in_array($theme, $allowedThemes)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid theme "%s". Allowed themes: %s', $theme, implode(', ', $allowedThemes))
            );
        }

        $this->settingsManager->set('theme', $theme);
    }

    public function setMaxUsers(int $maxUsers): void
    {
        if ($maxUsers < 1) {
            throw new \InvalidArgumentException('Max users must be at least 1');
        }

        if ($maxUsers > 10000) {
            throw new \InvalidArgumentException('Max users cannot exceed 10,000');
        }

        $this->settingsManager->set('max_users', $maxUsers);
    }
}
```

### Settings Groups

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class SettingsGroupService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
    ) {}

    public function getEmailSettings(): array
    {
        return [
            'from_address' => $this->settingsManager->get('email_from'),
            'sender_name' => $this->settingsManager->get('email_sender'),
            'smtp_host' => $this->settingsManager->get('smtp_host'),
            'smtp_port' => $this->settingsManager->get('smtp_port', 587),
            'smtp_encryption' => $this->settingsManager->get('smtp_encryption', 'tls'),
            'smtp_username' => $this->settingsManager->get('smtp_username'),
            'smtp_password' => $this->settingsManager->get('smtp_password'),
        ];
    }

    public function setEmailSettings(array $settings): void
    {
        $emailSettings = [];
        
        foreach ($settings as $key => $value) {
            if (str_starts_with($key, 'email_') || str_starts_with($key, 'smtp_')) {
                $emailSettings[$key] = $value;
            }
        }

        $this->settingsManager->setMultiple($emailSettings);
    }

    public function getUISettings(): array
    {
        return [
            'theme' => $this->settingsManager->get('theme', 'default'),
            'logo_url' => $this->settingsManager->get('logo_url'),
            'primary_color' => $this->settingsManager->get('primary_color', '#007bff'),
            'secondary_color' => $this->settingsManager->get('secondary_color', '#6c757d'),
            'sidebar_collapsed' => $this->settingsManager->get('sidebar_collapsed', false),
            'show_breadcrumbs' => $this->settingsManager->get('show_breadcrumbs', true),
        ];
    }

    public function getFeatureFlags(): array
    {
        return [
            'enable_notifications' => $this->settingsManager->get('feature_notifications', true),
            'enable_analytics' => $this->settingsManager->get('feature_analytics', false),
            'enable_api' => $this->settingsManager->get('feature_api', true),
            'enable_webhooks' => $this->settingsManager->get('feature_webhooks', false),
            'enable_sso' => $this->settingsManager->get('feature_sso', false),
        ];
    }
}
```

## Fallback System

### Hierarchical Fallbacks

The settings system uses a hierarchical fallback approach:

1. **Tenant-specific setting**: Value stored for the current tenant
2. **Bundle configuration**: Fallback values from bundle config
3. **Default value**: Value provided in the `get()` method call
4. **Null**: If no fallback is available

```php
// Example fallback chain for 'mailer_dsn':
// 1. Tenant setting: 'smtp://tenant-smtp.example.com:587'
// 2. Bundle config: 'smtp://localhost:1025' (fallback_dsn)
// 3. Method default: 'null://localhost' (provided in get() call)
// 4. Null: null

$mailerDsn = $settingsManager->get('mailer_dsn', 'null://localhost');
```

### Service-Specific Fallbacks

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;

class ServiceConfigurationService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
        private TenantMailerConfigurator $mailerConfigurator,
        private TenantMessengerConfigurator $messengerConfigurator,
    ) {}

    public function getMailerConfiguration(): array
    {
        return [
            // Uses bundle fallback configuration automatically
            'dsn' => $this->mailerConfigurator->getMailerDsn(),
            'from' => $this->mailerConfigurator->getFromAddress(),
            'sender' => $this->mailerConfigurator->getSenderName(),
        ];
    }

    public function getMessengerConfiguration(): array
    {
        return [
            // Uses bundle fallback configuration automatically
            'transport_dsn' => $this->messengerConfigurator->getTransportDsn(),
            'bus_name' => $this->messengerConfigurator->getBusName(),
        ];
    }

    public function getCustomConfiguration(): array
    {
        return [
            // Manual fallback to bundle config or default
            'api_key' => $this->settingsManager->get('api_key', 'default-api-key'),
            'webhook_url' => $this->settingsManager->get('webhook_url', 'https://example.com/webhook'),
            'timeout' => $this->settingsManager->get('timeout', 30),
        ];
    }
}
```

## Caching

### Cache Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    cache:
        enabled: true
        pool: 'cache.app' # or custom pool
        ttl: 3600 # Cache for 1 hour
```

### Cache Management

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class CacheAwareSettingsService
{
    public function __construct(
        private TenantSettingsManager $settingsManager,
    ) {}

    public function updateCriticalSetting(string $key, mixed $value): void
    {
        // Update setting
        $this->settingsManager->set($key, $value);
        
        // Clear cache for this tenant (if needed)
        // The settings manager handles cache invalidation automatically
        
        // For manual cache clearing, you can inject the cache pool
        // and clear specific keys if needed
    }

    public function bulkUpdateSettings(array $settings): void
    {
        // Use setMultiple for better performance
        $this->settingsManager->setMultiple($settings);
        
        // Cache is automatically invalidated for affected keys
    }
}
```

### Custom Cache Keys

```php
<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class CustomCacheService
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private TenantContextInterface $tenantContext,
    ) {}

    public function getCachedTenantData(string $key): mixed
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return null;
        }

        $cacheKey = sprintf('tenant_%s_%s', $tenant->getSlug(), $key);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        // Generate data
        $data = $this->generateTenantData($key);

        // Cache for 1 hour
        $cacheItem->set($data);
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);

        return $data;
    }

    private function generateTenantData(string $key): mixed
    {
        // Your data generation logic
        return [];
    }
}
```

## Console Commands

### List Settings

```bash
# List all settings for a tenant
php bin/console tenant:settings:list --tenant=acme

# List specific setting keys
php bin/console tenant:settings:list --tenant=acme --keys=theme,logo_url
```

### Set Settings

```bash
# Set a single setting
php bin/console tenant:settings:set --tenant=acme theme dark

# Set multiple settings
php bin/console tenant:settings:set --tenant=acme theme=dark logo_url=/logo.png
```

### Clear Cache

```bash
# Clear settings cache for specific tenant
php bin/console tenant:settings:clear-cache --tenant=acme

# Clear settings cache for all tenants
php bin/console tenant:settings:clear-cache --all
```

## Database Storage

### Settings Entity

The bundle automatically creates a settings entity to store tenant-specific values:

```sql
CREATE TABLE tenant_settings (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    setting_key VARCHAR(255) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    UNIQUE KEY unique_tenant_setting (tenant_id, setting_key)
);
```

### Custom Settings Entity

You can create a custom settings entity if needed:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'custom_tenant_settings')]
class CustomTenantSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TenantInterface $tenant = null;

    #[ORM\Column(length: 255)]
    private ?string $key = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = null; // 'string', 'integer', 'boolean', 'array', 'datetime'

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    // Getters and setters...
}
```

## Testing

### Unit Testing

```php
<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class SettingsServiceTest extends TestCase
{
    public function testGetSettingWithDefault(): void
    {
        $tenantContext = $this->createMock(TenantContextInterface::class);
        $settingsManager = $this->createMock(TenantSettingsManager::class);

        $settingsManager
            ->expects($this->once())
            ->method('get')
            ->with('theme', 'default')
            ->willReturn('dark');

        $service = new ConfigurationService($settingsManager);
        $config = $service->getAppConfiguration();

        $this->assertEquals('dark', $config['theme']);
    }

    public function testSetSetting(): void
    {
        $settingsManager = $this->createMock(TenantSettingsManager::class);

        $settingsManager
            ->expects($this->once())
            ->method('set')
            ->with('theme', 'dark');

        $service = new ConfigurationService($settingsManager);
        $service->updateTheme('dark');
    }
}
```

### Integration Testing

```php
<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantSettingsIntegrationTest extends KernelTestCase
{
    public function testSettingsWithRealTenant(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $settingsManager = $container->get(TenantSettingsManager::class);
        $tenantContext = $container->get(TenantContextInterface::class);

        // Create and set test tenant
        $tenant = $this->createTestTenant();
        $tenantContext->setTenant($tenant);

        // Test setting and getting values
        $settingsManager->set('test_key', 'test_value');
        $value = $settingsManager->get('test_key');

        $this->assertEquals('test_value', $value);
    }

    private function createTestTenant(): TenantInterface
    {
        // Create test tenant implementation
        return new class implements TenantInterface {
            public function getId(): ?int { return 1; }
            public function getSlug(): ?string { return 'test'; }
            public function getName(): ?string { return 'Test Tenant'; }
            // ... other required methods
        };
    }
}
```

## Best Practices

### 1. Use Type-Safe Getters

```php
// Good - type-safe with validation
public function getMaxUsers(): int
{
    $value = $this->settingsManager->get('max_users', 10);
    return is_int($value) ? $value : 10;
}

// Bad - no type safety
public function getMaxUsers()
{
    return $this->settingsManager->get('max_users', 10);
}
```

### 2. Group Related Settings

```php
// Good - grouped settings
public function getEmailConfiguration(): array
{
    return [
        'from' => $this->settingsManager->get('email_from'),
        'sender' => $this->settingsManager->get('email_sender'),
        'smtp_host' => $this->settingsManager->get('smtp_host'),
    ];
}

// Bad - scattered individual calls
$from = $this->settingsManager->get('email_from');
$sender = $this->settingsManager->get('email_sender');
$host = $this->settingsManager->get('smtp_host');
```

### 3. Use Meaningful Defaults

```php
// Good - meaningful defaults
$theme = $this->settingsManager->get('theme', 'default');
$timeout = $this->settingsManager->get('api_timeout', 30);
$enabled = $this->settingsManager->get('feature_enabled', false);

// Bad - no defaults or unclear defaults
$theme = $this->settingsManager->get('theme');
$timeout = $this->settingsManager->get('api_timeout', 0);
```

### 4. Validate Settings

```php
// Good - validate before setting
public function setTheme(string $theme): void
{
    $allowedThemes = ['default', 'dark', 'light'];
    
    if (!in_array($theme, $allowedThemes)) {
        throw new \InvalidArgumentException('Invalid theme');
    }
    
    $this->settingsManager->set('theme', $theme);
}

// Bad - no validation
public function setTheme(string $theme): void
{
    $this->settingsManager->set('theme', $theme);
}
```

### 5. Use Bulk Operations

```php
// Good - bulk update
$this->settingsManager->setMultiple([
    'theme' => 'dark',
    'logo_url' => '/new-logo.png',
    'updated_at' => new \DateTimeImmutable(),
]);

// Bad - multiple individual calls
$this->settingsManager->set('theme', 'dark');
$this->settingsManager->set('logo_url', '/new-logo.png');
$this->settingsManager->set('updated_at', new \DateTimeImmutable());
```

## Troubleshooting

### Common Issues

1. **Settings Not Persisting**: Check tenant context is set
2. **Cache Not Clearing**: Verify cache configuration
3. **Type Conversion Issues**: Use type-safe getters
4. **Performance Issues**: Use bulk operations and caching

### Debug Information

```php
// Check current tenant
$tenant = $this->tenantContext->getTenant();
echo $tenant ? $tenant->getSlug() : 'No tenant';

// Check setting value and source
$value = $this->settingsManager->get('key', 'default');
echo "Value: " . $value;

// Clear cache manually if needed
$this->settingsManager->clearCache();
```