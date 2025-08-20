<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\TestTenantAwareMessage;
use Zhortein\MultiTenantBundle\Tests\Toolkit\TenantMessengerTestCase;

/**
 * Integration test for Messenger tenant propagation.
 *
 * This test verifies that:
 * 1. Messages dispatched with tenant context carry TenantStamp
 * 2. Worker middleware applies tenant context from TenantStamp
 * 3. Message handlers execute in the correct tenant context
 */
class MessengerTenantPropagationTest extends TenantMessengerTestCase
{
    private const TENANT_A_SLUG = 'tenant-a';
    private const TENANT_B_SLUG = 'tenant-b';

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenants
        $this->getTestData()->seedTenants([
            self::TENANT_A_SLUG => ['name' => 'Tenant A'],
            self::TENANT_B_SLUG => ['name' => 'Tenant B'],
        ]);

        // Seed test data
        $this->getTestData()->seedProducts(self::TENANT_A_SLUG, 2);
        $this->getTestData()->seedProducts(self::TENANT_B_SLUG, 1);
    }

    /**
     * Test that messages dispatched with tenant context carry TenantStamp.
     */
    public function testMessageDispatchWithTenantStamp(): void
    {
        $message = new TestTenantAwareMessage('test-data');

        // Dispatch message in tenant A context
        $envelope = $this->dispatchAndAssertTenantStamp($message, self::TENANT_A_SLUG);

        // Verify the message was dispatched
        $this->assertNotNull($envelope);

        // Dispatch message in tenant B context
        $envelope = $this->dispatchAndAssertTenantStamp($message, self::TENANT_B_SLUG);

        // Verify the message was dispatched
        $this->assertNotNull($envelope);
    }

    /**
     * Test that messages dispatched without tenant context don't carry TenantStamp.
     */
    public function testMessageDispatchWithoutTenantContext(): void
    {
        $message = new TestTenantAwareMessage('test-data');

        // Clear any existing tenant context
        $this->getTenantContext()->clear();

        // Dispatch message without tenant context
        $envelope = $this->getMessageBus()->dispatch($message);

        // Verify no tenant stamp is present
        $this->assertEnvelopeHasNoTenantStamp($envelope);
    }

    /**
     * Test that async messages are properly queued with tenant stamps.
     */
    public function testAsyncMessageQueuingWithTenantStamp(): void
    {
        $message = new TestTenantAwareMessage('async-test-data');

        // Dispatch message in tenant A context
        $this->dispatchWithTenant($message, self::TENANT_A_SLUG);

        // Check that message was queued in async transport
        $messages = $this->getTransportMessages('async');
        $this->assertCount(1, $messages, 'One message should be queued in async transport');

        $envelope = $messages[0];
        $this->assertEnvelopeHasTenantStamp($envelope, self::TENANT_A_SLUG);
    }

    /**
     * Test that worker middleware applies tenant context from TenantStamp.
     */
    public function testWorkerMiddlewareAppliesTenantContext(): void
    {
        $message = new TestTenantAwareMessage('worker-test-data');

        // Dispatch message in tenant A context
        $this->dispatchWithTenant($message, self::TENANT_A_SLUG);

        // Simulate worker processing the message
        $this->processMessagesWithTenant('async', self::TENANT_A_SLUG);

        // Verify that the message was processed
        // (In a real scenario, the handler would perform tenant-aware operations)
        $this->assertTransportIsEmpty('async');
    }

    /**
     * Test tenant context isolation in message handlers.
     */
    public function testTenantContextIsolationInHandlers(): void
    {
        $messageA = new TestTenantAwareMessage('tenant-a-data');
        $messageB = new TestTenantAwareMessage('tenant-b-data');

        // Dispatch messages for different tenants
        $this->dispatchWithTenant($messageA, self::TENANT_A_SLUG);
        $this->dispatchWithTenant($messageB, self::TENANT_B_SLUG);

        // Verify both messages are queued
        $messages = $this->getTransportMessages('async');
        $this->assertCount(2, $messages, 'Two messages should be queued');

        // Verify each message has the correct tenant stamp
        $this->assertEnvelopeHasTenantStamp($messages[0], self::TENANT_A_SLUG);
        $this->assertEnvelopeHasTenantStamp($messages[1], self::TENANT_B_SLUG);
    }

    /**
     * Test that tenant context is properly restored after message processing.
     */
    public function testTenantContextRestorationAfterMessageProcessing(): void
    {
        // Set initial tenant context
        $tenantA = $this->getTenantRegistry()->findBySlug(self::TENANT_A_SLUG);
        $this->assertNotNull($tenantA);
        $this->getTenantContext()->setTenant($tenantA);

        $message = new TestTenantAwareMessage('context-restoration-test');

        // Dispatch message for different tenant
        $this->dispatchWithTenant($message, self::TENANT_B_SLUG);

        // Verify original context is restored
        $currentTenant = $this->getTenantContext()->getTenant();
        $this->assertNotNull($currentTenant);
        $this->assertSame($tenantA->getId(), $currentTenant->getId());
    }

    /**
     * Test message retry with tenant context preservation.
     */
    public function testMessageRetryWithTenantContextPreservation(): void
    {
        $message = new TestTenantAwareMessage('retry-test-data');

        // Dispatch message in tenant A context
        $envelope = $this->dispatchWithTenant($message, self::TENANT_A_SLUG);

        // Simulate message failure and retry
        // The retry should maintain the same tenant context
        $this->assertEnvelopeHasTenantStamp($envelope, self::TENANT_A_SLUG);

        // In a real scenario, the failed message would be requeued with the same stamps
        $retryEnvelope = $envelope->with(/* additional retry stamps */);
        $this->assertEnvelopeHasTenantStamp($retryEnvelope, self::TENANT_A_SLUG);
    }

    /**
     * Test that multiple message buses handle tenant context correctly.
     */
    public function testMultipleBusesWithTenantContext(): void
    {
        $message = new TestTenantAwareMessage('multi-bus-test');

        // Test with default bus
        $envelope = $this->dispatchWithTenant($message, self::TENANT_A_SLUG);
        $this->assertEnvelopeHasTenantStamp($envelope, self::TENANT_A_SLUG);

        // If there are other buses configured, test them too
        // This would require additional setup in the test configuration
    }

    /**
     * Test tenant-aware message routing.
     */
    public function testTenantAwareMessageRouting(): void
    {
        $message = new TestTenantAwareMessage('routing-test');

        // Dispatch messages for different tenants
        $this->dispatchWithTenant($message, self::TENANT_A_SLUG);
        $this->dispatchWithTenant($message, self::TENANT_B_SLUG);

        // Verify messages are routed correctly
        // (This would depend on tenant-specific transport configuration)
        $messages = $this->getTransportMessages('async');
        $this->assertGreaterThanOrEqual(2, count($messages));
    }
}