<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Decorator\TenantLoggerProcessor
 */
final class TenantLoggerProcessorTest extends TestCase
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

    public function testProcessWithTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $processor = new TenantLoggerProcessor($this->tenantContext);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $result = $processor($record);

        $this->assertSame('Test message', $result->message);
        $this->assertArrayHasKey('tenant_id', $result->extra);
        $this->assertArrayHasKey('tenant_slug', $result->extra);
        $this->assertSame('tenant-123', $result->extra['tenant_id']);
        $this->assertSame('test-tenant', $result->extra['tenant_slug']);
    }

    public function testProcessWithoutTenantContext(): void
    {
        $this->tenantContext->method('getTenant')->willReturn(null);

        $processor = new TenantLoggerProcessor($this->tenantContext);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $result = $processor($record);

        $this->assertSame('Test message', $result->message);
        $this->assertArrayNotHasKey('tenant_id', $result->extra);
        $this->assertArrayNotHasKey('tenant_slug', $result->extra);
    }

    public function testProcessWhenDisabled(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $processor = new TenantLoggerProcessor($this->tenantContext, false);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $result = $processor($record);

        $this->assertSame($record, $result);
        $this->assertArrayNotHasKey('tenant_id', $result->extra);
        $this->assertArrayNotHasKey('tenant_slug', $result->extra);
    }

    public function testProcessPreservesExistingExtra(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $processor = new TenantLoggerProcessor($this->tenantContext);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: ['existing_key' => 'existing_value']
        );

        $result = $processor($record);

        $this->assertArrayHasKey('existing_key', $result->extra);
        $this->assertSame('existing_value', $result->extra['existing_key']);
        $this->assertArrayHasKey('tenant_id', $result->extra);
        $this->assertArrayHasKey('tenant_slug', $result->extra);
        $this->assertSame('tenant-123', $result->extra['tenant_id']);
        $this->assertSame('test-tenant', $result->extra['tenant_slug']);
    }

    public function testProcessWithEmptyExtra(): void
    {
        $this->tenantContext->method('getTenant')->willReturn($this->tenant);

        $processor = new TenantLoggerProcessor($this->tenantContext);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $result = $processor($record);

        $this->assertCount(2, $result->extra);
        $this->assertArrayHasKey('tenant_id', $result->extra);
        $this->assertArrayHasKey('tenant_slug', $result->extra);
    }
}
