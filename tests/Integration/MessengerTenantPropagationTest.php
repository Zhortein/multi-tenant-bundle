<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;

/**
 * Integration test for tenant propagation through Symfony Messenger.
 *
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantStamp
 */
class MessengerTenantPropagationTest extends TestCase
{
    private TenantContext $tenantContext;
    private InMemoryTenantRegistry $tenantRegistry;
    private TenantSessionConfigurator $sessionConfigurator;
    private TenantSendingMiddleware $sendingMiddleware;
    private TenantWorkerMiddleware $workerMiddleware;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        $this->tenantRegistry = new InMemoryTenantRegistry();

        // Create a real TenantSessionConfigurator with mocked dependencies
        $connection = $this->createMock(Connection::class);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $logger = $this->createMock(LoggerInterface::class);

        $this->sessionConfigurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $connection,
            $this->tenantRegistry,
            false, // RLS disabled for testing
            'app.tenant_id',
            $logger
        );

        $this->sendingMiddleware = new TenantSendingMiddleware($this->tenantContext);
        $this->workerMiddleware = new TenantWorkerMiddleware(
            $this->tenantContext,
            $this->tenantRegistry,
            $this->sessionConfigurator
        );
    }

    public function testFullTenantPropagationFlow(): void
    {
        // Arrange: Create a tenant and add it to registry
        $tenant = $this->createMockTenant('123', 'acme');
        $this->tenantRegistry->addTenant($tenant);

        // Set tenant context (simulating a web request with tenant resolution)
        $this->tenantContext->setTenant($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Act 1: Sending middleware should attach TenantStamp
        $sendingStack = $this->createMockStack($envelope);
        $envelopeWithStamp = $this->sendingMiddleware->handle($envelope, $sendingStack);

        // Assert: TenantStamp was attached
        $stamp = $envelopeWithStamp->last(TenantStamp::class);
        $this->assertInstanceOf(TenantStamp::class, $stamp);
        $this->assertSame('123', $stamp->getTenantId());

        // Clear tenant context (simulating end of web request)
        $this->tenantContext->clear();
        $this->assertFalse($this->tenantContext->hasTenant());

        // Act 2: Worker middleware should restore tenant context
        $workerStack = $this->createMockStack($envelopeWithStamp);
        $processedEnvelope = $this->workerMiddleware->handle($envelopeWithStamp, $workerStack);

        // Assert: Message was processed and tenant context was cleared
        $this->assertSame($envelopeWithStamp, $processedEnvelope);
        $this->assertFalse($this->tenantContext->hasTenant()); // Should be cleared after processing
    }

    public function testSendingWithoutTenantContextDoesNotAttachStamp(): void
    {
        // Arrange: No tenant context set
        $this->assertFalse($this->tenantContext->hasTenant());

        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Act: Sending middleware should not attach TenantStamp
        $sendingStack = $this->createMockStack($envelope);
        $processedEnvelope = $this->sendingMiddleware->handle($envelope, $sendingStack);

        // Assert: No TenantStamp was attached
        $this->assertNull($processedEnvelope->last(TenantStamp::class));
    }

    public function testWorkerWithoutTenantStampProceedsSafely(): void
    {
        // Arrange: Message without TenantStamp
        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Act: Worker middleware should proceed without setting tenant context

        $workerStack = $this->createMockStack($envelope);
        $processedEnvelope = $this->workerMiddleware->handle($envelope, $workerStack);

        // Assert: Message was processed without tenant context
        $this->assertSame($envelope, $processedEnvelope);
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testWorkerWithNonexistentTenantProceedsSafely(): void
    {
        // Arrange: Message with TenantStamp for nonexistent tenant
        $message = new \stdClass();
        $tenantStamp = new TenantStamp('nonexistent');
        $envelope = new Envelope($message, [$tenantStamp]);

        // Act: Worker middleware should proceed without setting tenant context

        $workerStack = $this->createMockStack($envelope);
        $processedEnvelope = $this->workerMiddleware->handle($envelope, $workerStack);

        // Assert: Message was processed without tenant context
        $this->assertSame($envelope, $processedEnvelope);
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSendingMiddlewareDoesNotOverrideExistingStamp(): void
    {
        // Arrange: Create tenant and set context
        $tenant = $this->createMockTenant('123', 'acme');
        $this->tenantContext->setTenant($tenant);

        // Create envelope with existing TenantStamp
        $message = new \stdClass();
        $existingStamp = new TenantStamp('456');
        $envelope = new Envelope($message, [$existingStamp]);

        // Act: Sending middleware should not add another stamp
        $sendingStack = $this->createMockStack($envelope);
        $processedEnvelope = $this->sendingMiddleware->handle($envelope, $sendingStack);

        // Assert: Original stamp is preserved
        $stamp = $processedEnvelope->last(TenantStamp::class);
        $this->assertInstanceOf(TenantStamp::class, $stamp);
        $this->assertSame('456', $stamp->getTenantId()); // Original stamp preserved
    }

    private function createMockTenant(string $id, string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getMailerDsn')->willReturn(null);
        $tenant->method('getMessengerDsn')->willReturn(null);

        return $tenant;
    }

    private function createMockStack(Envelope $expectedEnvelope): StackInterface
    {
        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);

        $stack->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->method('handle')->willReturnCallback(function (Envelope $envelope) {
            return $envelope; // Return the envelope as-is (with any modifications)
        });

        return $stack;
    }
}
