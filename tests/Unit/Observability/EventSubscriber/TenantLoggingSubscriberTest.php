<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantLoggingSubscriber;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantLoggingSubscriber
 */
final class TenantLoggingSubscriberTest extends TestCase
{
    private LoggerInterface $logger;
    private TenantLoggingSubscriber $subscriber;
    /** @var array<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $this->logRecords = [];
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Configure the mock to capture log calls
        $this->logger->method('info')->willReturnCallback(function (string $message, array $context = []) {
            $this->logRecords[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) {
            $this->logRecords[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        });
        
        $this->logger->method('error')->willReturnCallback(function (string $message, array $context = []) {
            $this->logRecords[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });
        
        $this->subscriber = new TenantLoggingSubscriber($this->logger);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = TenantLoggingSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TenantResolvedEvent::class, $events);
        $this->assertArrayHasKey(TenantResolutionFailedEvent::class, $events);
        $this->assertArrayHasKey(TenantContextStartedEvent::class, $events);
        $this->assertArrayHasKey(TenantContextEndedEvent::class, $events);
        $this->assertArrayHasKey(TenantRlsAppliedEvent::class, $events);
        $this->assertArrayHasKey(TenantHeaderRejectedEvent::class, $events);
    }

    public function testOnTenantResolved(): void
    {
        $event = new TenantResolvedEvent('subdomain', '123');

        $this->subscriber->onTenantResolved($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('info', $record['level']);
        $this->assertStringContainsString('Tenant successfully resolved', $record['message']);
        $this->assertSame('123', $record['context']['tenant_id']);
        $this->assertSame('subdomain', $record['context']['resolver']);
    }

    public function testOnTenantResolutionFailed(): void
    {
        $event = new TenantResolutionFailedEvent('header', 'no_tenant_found', ['uri' => '/test']);

        $this->subscriber->onTenantResolutionFailed($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('warning', $record['level']);
        $this->assertStringContainsString('Tenant resolution failed', $record['message']);
        $this->assertSame('header', $record['context']['resolver']);
        $this->assertSame('no_tenant_found', $record['context']['reason']);
        $this->assertSame(['uri' => '/test'], $record['context']['context']);
    }

    public function testOnTenantContextStarted(): void
    {
        $event = new TenantContextStartedEvent('123');

        $this->subscriber->onTenantContextStarted($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('info', $record['level']);
        $this->assertStringContainsString('Tenant context started', $record['message']);
        $this->assertSame('123', $record['context']['tenant_id']);
    }

    public function testOnTenantContextEnded(): void
    {
        $event = new TenantContextEndedEvent('123');

        $this->subscriber->onTenantContextEnded($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('info', $record['level']);
        $this->assertStringContainsString('Tenant context ended', $record['message']);
        $this->assertSame('123', $record['context']['tenant_id']);
    }

    public function testOnTenantContextEndedWithNullTenant(): void
    {
        $event = new TenantContextEndedEvent(null);

        $this->subscriber->onTenantContextEnded($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('info', $record['level']);
        $this->assertStringContainsString('Tenant context ended', $record['message']);
        $this->assertNull($record['context']['tenant_id']);
    }

    public function testOnTenantRlsAppliedSuccess(): void
    {
        $event = new TenantRlsAppliedEvent('123', true);

        $this->subscriber->onTenantRlsApplied($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('info', $record['level']);
        $this->assertStringContainsString('Tenant RLS successfully applied', $record['message']);
        $this->assertSame('123', $record['context']['tenant_id']);
    }

    public function testOnTenantRlsAppliedFailure(): void
    {
        $event = new TenantRlsAppliedEvent('123', false, 'Connection failed');

        $this->subscriber->onTenantRlsApplied($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('error', $record['level']);
        $this->assertStringContainsString('Tenant RLS application failed', $record['message']);
        $this->assertSame('123', $record['context']['tenant_id']);
        $this->assertSame('Connection failed', $record['context']['error_message']);
    }

    public function testOnTenantHeaderRejected(): void
    {
        $event = new TenantHeaderRejectedEvent('X-Custom-Tenant');

        $this->subscriber->onTenantHeaderRejected($event);

        $this->assertCount(1, $this->logRecords);
        $record = $this->logRecords[0];
        $this->assertSame('warning', $record['level']);
        $this->assertStringContainsString('Tenant header rejected by allow-list', $record['message']);
        $this->assertSame('X-Custom-Tenant', $record['context']['header_name']);
    }

    public function testWithoutLogger(): void
    {
        $subscriber = new TenantLoggingSubscriber();
        $event = new TenantResolvedEvent('test', '123');

        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $subscriber->onTenantResolved($event);
    }
}