<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Observability;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantLoggingSubscriber;
use Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantMetricsSubscriber;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver;
use Zhortein\MultiTenantBundle\Resolver\HeaderTenantResolver;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Unit\Observability\Metrics\MockMetricsAdapter;

/**
 * Integration test for the complete observability system.
 *
 * @covers \Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantMetricsSubscriber
 * @covers \Zhortein\MultiTenantBundle\Observability\EventSubscriber\TenantLoggingSubscriber
 * @covers \Zhortein\MultiTenantBundle\Resolver\ChainTenantResolver
 * @covers \Zhortein\MultiTenantBundle\Context\TenantContext
 */
final class CompleteObservabilityTest extends TestCase
{
    private EventDispatcher $eventDispatcher;
    private MockMetricsAdapter $metricsAdapter;
    private LoggerInterface $logger;
    private InMemoryTenantRegistry $tenantRegistry;
    /** @var array<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $this->eventDispatcher = new EventDispatcher();
        $this->metricsAdapter = new MockMetricsAdapter();
        $this->logRecords = [];

        // Create mock logger
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('info')->willReturnCallback(function (string $message, array $context = []) {
            $this->logRecords[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) {
            $this->logRecords[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        });

        // Register subscribers
        $metricsSubscriber = new TenantMetricsSubscriber($this->metricsAdapter);
        $loggingSubscriber = new TenantLoggingSubscriber($this->logger);

        $this->eventDispatcher->addSubscriber($metricsSubscriber);
        $this->eventDispatcher->addSubscriber($loggingSubscriber);

        // Create tenant registry with test data
        $tenant = new TestTenant();
        $tenant->setId(123);
        $tenant->setSlug('test-tenant');

        $this->tenantRegistry = new InMemoryTenantRegistry([$tenant]);
    }

    public function testSuccessfulTenantResolutionGeneratesObservabilityData(): void
    {
        // Create resolver with observability
        $headerResolver = new HeaderTenantResolver(
            $this->tenantRegistry,
            'X-Tenant-Slug'
        );

        $chainResolver = new ChainTenantResolver(
            ['header' => $headerResolver],
            ['header'],
            false, // non-strict mode
            [],
            null,
            $this->eventDispatcher
        );

        // Create request that should resolve to tenant
        $request = Request::create('https://example.com/api/test');
        $request->headers->set('X-Tenant-Slug', 'test-tenant');

        // Resolve tenant
        $resolvedTenant = $chainResolver->resolveTenant($request);

        // Verify tenant was resolved
        $this->assertNotNull($resolvedTenant);
        $this->assertSame('test-tenant', $resolvedTenant->getSlug());

        // Verify metrics were collected
        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_resolution_total', $counter['name']);
        $this->assertSame(['resolver' => 'header', 'status' => 'ok'], $counter['labels']);
        $this->assertSame(1, $counter['value']);

        // Verify logs were generated
        $this->assertCount(1, $this->logRecords);
        $logRecord = $this->logRecords[0];
        $this->assertSame('info', $logRecord['level']);
        $this->assertStringContainsString('Tenant successfully resolved', $logRecord['message']);
        $this->assertSame('123', $logRecord['context']['tenant_id']);
        $this->assertSame('header', $logRecord['context']['resolver']);
    }

    public function testFailedTenantResolutionGeneratesObservabilityData(): void
    {
        // Create resolver with observability
        $headerResolver = new HeaderTenantResolver(
            $this->tenantRegistry,
            'X-Tenant-Slug'
        );

        $chainResolver = new ChainTenantResolver(
            ['header' => $headerResolver],
            ['header'],
            false, // non-strict mode
            [],
            null,
            $this->eventDispatcher
        );

        // Create request that should NOT resolve to any tenant
        $request = Request::create('https://example.com/api/test');
        $request->headers->set('X-Tenant-Slug', 'nonexistent-tenant');

        // Attempt to resolve tenant
        $resolvedTenant = $chainResolver->resolveTenant($request);

        // Verify tenant was not resolved
        $this->assertNull($resolvedTenant);

        // Verify metrics were collected for failure
        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(1, $counters);

        $counter = $counters[0];
        $this->assertSame('tenant_resolution_total', $counter['name']);
        $this->assertSame([
            'resolver' => 'header',
            'status' => 'error',
            'reason' => 'no_tenant_found',
        ], $counter['labels']);
        $this->assertSame(1, $counter['value']);

        // Verify logs were generated for failure
        $this->assertCount(1, $this->logRecords);
        $logRecord = $this->logRecords[0];
        $this->assertSame('warning', $logRecord['level']);
        $this->assertStringContainsString('Tenant resolution failed', $logRecord['message']);
        $this->assertSame('header', $logRecord['context']['resolver']);
        $this->assertSame('no_tenant_found', $logRecord['context']['reason']);
    }

    public function testTenantContextLifecycleGeneratesObservabilityData(): void
    {
        // Reset log records for this test
        $this->logRecords = [];
        $this->metricsAdapter->reset();
        
        $tenantContext = new TenantContext($this->eventDispatcher);

        $tenant = new TestTenant();
        $tenant->setId(456);
        $tenant->setSlug('context-tenant');

        // Set tenant context
        $tenantContext->setTenant($tenant);

        // Clear tenant context
        $tenantContext->clear();

        // Verify context lifecycle was logged
        $this->assertCount(2, $this->logRecords);

        // Context started
        $startRecord = $this->logRecords[0];
        $this->assertSame('info', $startRecord['level']);
        $this->assertStringContainsString('Tenant context started', $startRecord['message']);
        $this->assertSame('456', $startRecord['context']['tenant_id']);

        // Context ended
        $endRecord = $this->logRecords[1];
        $this->assertSame('info', $endRecord['level']);
        $this->assertStringContainsString('Tenant context ended', $endRecord['message']);
        $this->assertSame('456', $endRecord['context']['tenant_id']);
    }

    public function testDirectEventDispatchGeneratesObservabilityData(): void
    {
        // Dispatch events directly to test subscriber behavior
        $resolvedEvent = new TenantResolvedEvent('header', '789');
        $failedEvent = new TenantResolutionFailedEvent('path', 'invalid_format', ['path' => '/invalid']);

        $this->eventDispatcher->dispatch($resolvedEvent);
        $this->eventDispatcher->dispatch($failedEvent);

        // Verify metrics
        $counters = $this->metricsAdapter->getCounters();
        $this->assertCount(2, $counters);

        // Success counter
        $successCounter = $counters[0];
        $this->assertSame('tenant_resolution_total', $successCounter['name']);
        $this->assertSame(['resolver' => 'header', 'status' => 'ok'], $successCounter['labels']);

        // Failure counter
        $failureCounter = $counters[1];
        $this->assertSame('tenant_resolution_total', $failureCounter['name']);
        $this->assertSame([
            'resolver' => 'path',
            'status' => 'error',
            'reason' => 'invalid_format',
        ], $failureCounter['labels']);

        // Verify logs
        $this->assertCount(2, $this->logRecords);

        // Success log
        $successLog = $this->logRecords[0];
        $this->assertSame('info', $successLog['level']);
        $this->assertStringContainsString('Tenant successfully resolved', $successLog['message']);
        $this->assertSame('789', $successLog['context']['tenant_id']);

        // Failure log
        $failureLog = $this->logRecords[1];
        $this->assertSame('warning', $failureLog['level']);
        $this->assertStringContainsString('Tenant resolution failed', $failureLog['message']);
        $this->assertSame('path', $failureLog['context']['resolver']);
        $this->assertSame('invalid_format', $failureLog['context']['reason']);
    }
}