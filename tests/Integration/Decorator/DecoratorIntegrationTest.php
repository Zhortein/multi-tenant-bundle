<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Decorator;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor;
use Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Integration tests for tenant-aware decorators.
 *
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheDecorator
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantAwareSimpleCacheDecorator
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantStoragePathHelper
 */
final class DecoratorIntegrationTest extends TestCase
{
    private TenantContext $tenantContext;
    private TenantInterface $tenant1;
    private TenantInterface $tenant2;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();

        $this->tenant1 = $this->createMock(TenantInterface::class);
        $this->tenant1->method('getId')->willReturn('tenant-1');
        $this->tenant1->method('getSlug')->willReturn('tenant-one');

        $this->tenant2 = $this->createMock(TenantInterface::class);
        $this->tenant2->method('getId')->willReturn('tenant-2');
        $this->tenant2->method('getSlug')->willReturn('tenant-two');
    }

    public function testCacheDecoratorIsolatesTenants(): void
    {
        $baseCache = new ArrayAdapter();
        $decorator = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);

        // Set data for tenant 1
        $this->tenantContext->setTenant($this->tenant1);
        $item1 = $decorator->getItem('test-key');
        $item1->set('tenant-1-value');
        $decorator->save($item1);

        // Set data for tenant 2
        $this->tenantContext->setTenant($this->tenant2);
        $item2 = $decorator->getItem('test-key');
        $item2->set('tenant-2-value');
        $decorator->save($item2);

        // Verify tenant 1 data
        $this->tenantContext->setTenant($this->tenant1);
        $retrievedItem1 = $decorator->getItem('test-key');
        $this->assertTrue($retrievedItem1->isHit());
        $this->assertSame('tenant-1-value', $retrievedItem1->get());

        // Verify tenant 2 data
        $this->tenantContext->setTenant($this->tenant2);
        $retrievedItem2 = $decorator->getItem('test-key');
        $this->assertTrue($retrievedItem2->isHit());
        $this->assertSame('tenant-2-value', $retrievedItem2->get());

        // Verify no cross-tenant contamination
        $this->assertNotSame($retrievedItem1->get(), $retrievedItem2->get());
    }

    public function testSimpleCacheDecoratorIsolatesTenants(): void
    {
        $baseCache = new Psr16Cache(new ArrayAdapter());
        $decorator = new TenantAwareSimpleCacheDecorator($baseCache, $this->tenantContext);

        // Set data for tenant 1
        $this->tenantContext->setTenant($this->tenant1);
        $decorator->set('test-key', 'tenant-1-value');

        // Set data for tenant 2
        $this->tenantContext->setTenant($this->tenant2);
        $decorator->set('test-key', 'tenant-2-value');

        // Verify tenant 1 data
        $this->tenantContext->setTenant($this->tenant1);
        $this->assertSame('tenant-1-value', $decorator->get('test-key'));

        // Verify tenant 2 data
        $this->tenantContext->setTenant($this->tenant2);
        $this->assertSame('tenant-2-value', $decorator->get('test-key'));
    }

    public function testLoggerProcessorAddsTenantInfo(): void
    {
        $processor = new TenantLoggerProcessor($this->tenantContext);

        $this->tenantContext->setTenant($this->tenant1);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $processedRecord = $processor($record);

        $this->assertArrayHasKey('tenant_id', $processedRecord->extra);
        $this->assertArrayHasKey('tenant_slug', $processedRecord->extra);
        $this->assertSame('tenant-1', $processedRecord->extra['tenant_id']);
        $this->assertSame('tenant-one', $processedRecord->extra['tenant_slug']);
    }

    public function testStoragePathHelperGeneratesTenantPaths(): void
    {
        $helper = new TenantStoragePathHelper($this->tenantContext);

        // Test with tenant 1
        $this->tenantContext->setTenant($this->tenant1);
        $path1 = $helper->prefixPath('uploads/file.txt');
        $this->assertSame('tenants/tenant-1/uploads/file.txt', $path1);

        // Test with tenant 2
        $this->tenantContext->setTenant($this->tenant2);
        $path2 = $helper->prefixPath('uploads/file.txt');
        $this->assertSame('tenants/tenant-2/uploads/file.txt', $path2);

        // Verify different paths for different tenants
        $this->assertNotSame($path1, $path2);
    }

    public function testMultipleDecoratorsWorkTogether(): void
    {
        // Setup cache decorator
        $baseCache = new ArrayAdapter();
        $cacheDecorator = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);

        // Setup storage helper
        $storageHelper = new TenantStoragePathHelper($this->tenantContext);

        // Setup logger processor
        $loggerProcessor = new TenantLoggerProcessor($this->tenantContext);

        // Set tenant context
        $this->tenantContext->setTenant($this->tenant1);

        // Test cache
        $cacheItem = $cacheDecorator->getItem('integration-test');
        $cacheItem->set('test-value');
        $cacheDecorator->save($cacheItem);

        // Test storage
        $storagePath = $storageHelper->prefixPath('test/file.txt');

        // Test logger
        $logRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Integration test',
            context: [],
            extra: []
        );
        $processedRecord = $loggerProcessor($logRecord);

        // Verify all decorators use the same tenant context
        $retrievedItem = $cacheDecorator->getItem('integration-test');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertSame('test-value', $retrievedItem->get());

        $this->assertSame('tenants/tenant-1/test/file.txt', $storagePath);

        $this->assertSame('tenant-1', $processedRecord->extra['tenant_id']);
        $this->assertSame('tenant-one', $processedRecord->extra['tenant_slug']);
    }

    public function testDecoratorsHandleTenantSwitching(): void
    {
        $baseCache = new ArrayAdapter();
        $cacheDecorator = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);
        $storageHelper = new TenantStoragePathHelper($this->tenantContext);

        // Start with tenant 1
        $this->tenantContext->setTenant($this->tenant1);
        $item1 = $cacheDecorator->getItem('switch-test');
        $item1->set('tenant-1-data');
        $cacheDecorator->save($item1);
        $path1 = $storageHelper->prefixPath('file.txt');

        // Switch to tenant 2
        $this->tenantContext->setTenant($this->tenant2);
        $item2 = $cacheDecorator->getItem('switch-test');
        $this->assertFalse($item2->isHit()); // Should not see tenant 1's data
        $item2->set('tenant-2-data');
        $cacheDecorator->save($item2);
        $path2 = $storageHelper->prefixPath('file.txt');

        // Switch back to tenant 1
        $this->tenantContext->setTenant($this->tenant1);
        $retrievedItem1 = $cacheDecorator->getItem('switch-test');
        $this->assertTrue($retrievedItem1->isHit());
        $this->assertSame('tenant-1-data', $retrievedItem1->get());
        $retrievedPath1 = $storageHelper->prefixPath('file.txt');

        // Verify isolation
        $this->assertSame('tenants/tenant-1/file.txt', $path1);
        $this->assertSame('tenants/tenant-2/file.txt', $path2);
        $this->assertSame($path1, $retrievedPath1);
        $this->assertNotSame($path1, $path2);
    }

    public function testDecoratorsWithNoTenantContext(): void
    {
        $baseCache = new ArrayAdapter();
        $cacheDecorator = new TenantAwareCacheDecorator($baseCache, $this->tenantContext);
        $storageHelper = new TenantStoragePathHelper($this->tenantContext);
        $loggerProcessor = new TenantLoggerProcessor($this->tenantContext);

        // No tenant set - should work without prefixing
        $this->tenantContext->clear();

        // Test cache without tenant
        $item = $cacheDecorator->getItem('no-tenant-test');
        $item->set('no-tenant-value');
        $cacheDecorator->save($item);

        $retrievedItem = $cacheDecorator->getItem('no-tenant-test');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertSame('no-tenant-value', $retrievedItem->get());

        // Test storage without tenant
        $path = $storageHelper->prefixPath('file.txt');
        $this->assertSame('file.txt', $path); // No prefix

        // Test logger without tenant
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'No tenant test',
            context: [],
            extra: []
        );
        $processedRecord = $loggerProcessor($record);
        $this->assertArrayNotHasKey('tenant_id', $processedRecord->extra);
        $this->assertArrayNotHasKey('tenant_slug', $processedRecord->extra);
    }
}
