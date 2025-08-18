<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Functional\Decorator;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor;
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Functional tests for tenant-aware decorators in real-world scenarios.
 *
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper
 */
final class DecoratorFunctionalTest extends TestCase
{
    private ContainerBuilder $container;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->tenantContext = new TenantContext();

        // Register services in container
        $this->container->set('tenant_context', $this->tenantContext);
        $this->container->set('base_cache', new ArrayAdapter());
        $this->container->set('base_simple_cache', new Psr16Cache(new ArrayAdapter()));
    }

    public function testCacheDecoratorInServiceContainer(): void
    {
        // Register decorated cache service
        $baseCache = $this->container->get('base_cache');
        $decoratedCache = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);
        $this->container->set('decorated_cache', $decoratedCache);

        // Create mock tenant
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('functional-test');
        $tenant->method('getSlug')->willReturn('func-test');

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        // Use decorated cache
        $cache = $this->container->get('decorated_cache');
        $item = $cache->getItem('functional-key');
        $item->set('functional-value');
        $cache->save($item);

        // Verify data is stored with tenant prefix
        $retrievedItem = $cache->getItem('functional-key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertSame('functional-value', $retrievedItem->get());

        // Verify base cache has prefixed key
        $baseItem = $baseCache->getItem('tenant_functional-test_functional-key');
        $this->assertTrue($baseItem->isHit());
        $this->assertSame('functional-value', $baseItem->get());
    }

    public function testSimpleCacheDecoratorInServiceContainer(): void
    {
        // Register decorated simple cache service
        $baseCache = $this->container->get('base_simple_cache');
        $decoratedCache = new TenantAwareSimpleCacheDecorator($baseCache, $this->tenantContext);
        $this->container->set('decorated_simple_cache', $decoratedCache);

        // Create mock tenant
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('simple-test');

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        // Use decorated cache
        $cache = $this->container->get('decorated_simple_cache');
        $cache->set('simple-key', 'simple-value');

        // Verify data retrieval
        $this->assertSame('simple-value', $cache->get('simple-key'));
        $this->assertTrue($cache->has('simple-key'));

        // Verify base cache has prefixed key
        $this->assertTrue($baseCache->has('tenant_simple-test_simple-key'));
        $this->assertSame('simple-value', $baseCache->get('tenant_simple-test_simple-key'));
    }

    public function testLoggerProcessorInServiceContainer(): void
    {
        // Register logger processor
        $processor = new TenantLoggerProcessor($this->tenantContext);
        $this->container->set('tenant_logger_processor', $processor);

        // Create mock tenant
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('logger-test');
        $tenant->method('getSlug')->willReturn('log-test');

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        // Use processor
        $loggerProcessor = $this->container->get('tenant_logger_processor');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'functional',
            level: Level::Info,
            message: 'Functional test log',
            context: ['test' => true],
            extra: ['existing' => 'data']
        );

        $processedRecord = $loggerProcessor($record);

        // Verify tenant information is added
        $this->assertArrayHasKey('tenant_id', $processedRecord->extra);
        $this->assertArrayHasKey('tenant_slug', $processedRecord->extra);
        $this->assertArrayHasKey('existing', $processedRecord->extra);
        $this->assertSame('logger-test', $processedRecord->extra['tenant_id']);
        $this->assertSame('log-test', $processedRecord->extra['tenant_slug']);
        $this->assertSame('data', $processedRecord->extra['existing']);
    }

    public function testStoragePathHelperInServiceContainer(): void
    {
        // Register storage path helper
        $helper = new TenantStoragePathHelper($this->tenantContext);
        $this->container->set('tenant_storage_helper', $helper);

        // Create mock tenant
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('storage-test');
        $tenant->method('getSlug')->willReturn('store-test');

        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        // Use storage helper
        $storageHelper = $this->container->get('tenant_storage_helper');

        // Test various path operations
        $uploadPath = $storageHelper->createUploadPath('document.pdf', 'documents');
        $this->assertSame('tenants/storage-test/documents/document.pdf', $uploadPath);

        $tenantDir = $storageHelper->getTenantDirectory();
        $this->assertSame('tenants/storage-test', $tenantDir);

        $tenantDirSlug = $storageHelper->getTenantDirectory(true);
        $this->assertSame('tenants/store-test', $tenantDirSlug);

        $prefixedPath = $storageHelper->prefixPath('images/logo.png');
        $this->assertSame('tenants/storage-test/images/logo.png', $prefixedPath);

        $this->assertTrue($storageHelper->isTenantPrefixed($prefixedPath));
        $this->assertFalse($storageHelper->isTenantPrefixed('images/logo.png'));

        $unprefixedPath = $storageHelper->removeTenantPrefix($prefixedPath);
        $this->assertSame('images/logo.png', $unprefixedPath);
    }

    public function testRealWorldScenario(): void
    {
        // Setup all decorators
        $baseCache = new ArrayAdapter();
        $cacheDecorator = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);

        $baseSimpleCache = new Psr16Cache(new ArrayAdapter());
        $simpleCacheDecorator = new TenantAwareSimpleCacheDecorator($baseSimpleCache, $this->tenantContext);

        $loggerProcessor = new TenantLoggerProcessor($this->tenantContext);
        $storageHelper = new TenantStoragePathHelper($this->tenantContext);

        // Register in container
        $this->container->set('cache', $cacheDecorator);
        $this->container->set('simple_cache', $simpleCacheDecorator);
        $this->container->set('logger_processor', $loggerProcessor);
        $this->container->set('storage_helper', $storageHelper);

        // Simulate multi-tenant application workflow
        $tenants = [
            $this->createTenant('company-a', 'Company A'),
            $this->createTenant('company-b', 'Company B'),
        ];

        foreach ($tenants as $tenant) {
            // Switch tenant context
            $this->tenantContext->setTenant($tenant);

            // Simulate caching user preferences
            $cache = $this->container->get('cache');
            $prefsItem = $cache->getItem('user_preferences');
            $prefsItem->set(['theme' => 'dark', 'language' => 'en']);
            $cache->save($prefsItem);

            // Simulate caching session data
            $simpleCache = $this->container->get('simple_cache');
            $simpleCache->set('session_data', ['user_id' => 123, 'role' => 'admin']);

            // Simulate file upload path generation
            $storageHelper = $this->container->get('storage_helper');
            $uploadPath = $storageHelper->createUploadPath('report.pdf', 'reports');

            // Simulate logging
            $processor = $this->container->get('logger_processor');
            $logRecord = new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: 'app',
                level: Level::Info,
                message: 'User uploaded report',
                context: ['file' => 'report.pdf'],
                extra: []
            );
            $processedRecord = $processor($logRecord);

            // Verify tenant isolation
            $retrievedPrefsItem = $cache->getItem('user_preferences');
            $this->assertTrue($retrievedPrefsItem->isHit());
            $this->assertSame(['theme' => 'dark', 'language' => 'en'], $retrievedPrefsItem->get());
            $this->assertSame(['user_id' => 123, 'role' => 'admin'], $simpleCache->get('session_data'));
            $this->assertStringContainsString($tenant->getId(), $uploadPath);
            $this->assertSame($tenant->getId(), $processedRecord->extra['tenant_id']);
            $this->assertSame($tenant->getSlug(), $processedRecord->extra['tenant_slug']);
        }

        // Verify data isolation between tenants
        $this->tenantContext->setTenant($tenants[0]);
        $cache = $this->container->get('cache');
        $tenant1Prefs = $cache->getItem('user_preferences');
        $this->assertTrue($tenant1Prefs->isHit());

        $this->tenantContext->setTenant($tenants[1]);
        $tenant2Prefs = $cache->getItem('user_preferences');
        $this->assertTrue($tenant2Prefs->isHit());

        // Both tenants should have their own data
        $this->assertSame($tenant1Prefs->get(), $tenant2Prefs->get());
        // But they should be stored separately in the base cache
        $tenant1BaseItem = $baseCache->getItem('tenant_company-a_user_preferences');
        $tenant2BaseItem = $baseCache->getItem('tenant_company-b_user_preferences');
        $this->assertTrue($tenant1BaseItem->isHit());
        $this->assertTrue($tenant2BaseItem->isHit());
    }

    public function testDecoratorConfiguration(): void
    {
        // Test disabled decorators
        $disabledCacheDecorator = new TenantAwareCacheDecorator(
            new ArrayAdapter(),
            $this->tenantContext,
            false
        );

        $disabledLoggerProcessor = new TenantLoggerProcessor($this->tenantContext, false);
        $disabledStorageHelper = new TenantStoragePathHelper($this->tenantContext, false);

        // Create tenant
        $tenant = $this->createTenant('disabled-test', 'Disabled Test');
        $this->tenantContext->setTenant($tenant);

        // Test disabled cache decorator
        $item = $disabledCacheDecorator->getItem('test-key');
        $item->set('test-value');
        $disabledCacheDecorator->save($item);

        // Should work but without tenant prefixing
        $retrievedItem = $disabledCacheDecorator->getItem('test-key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertSame('test-value', $retrievedItem->get());

        // Test disabled logger processor
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test',
            context: [],
            extra: []
        );
        $processedRecord = $disabledLoggerProcessor($record);
        $this->assertSame($record, $processedRecord); // Should return unchanged

        // Test disabled storage helper
        $path = $disabledStorageHelper->prefixPath('test/file.txt');
        $this->assertSame('test/file.txt', $path); // Should return unchanged
    }

    private function createTenant(string $id, string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }
}
