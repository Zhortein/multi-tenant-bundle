<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Toolkit;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Base test case for Messenger tests with tenant context support.
 *
 * This class extends KernelTestCase and provides utilities for:
 * - Testing message dispatching with tenant context
 * - Verifying tenant stamp propagation
 * - Testing tenant-aware message handlers
 * - Managing test transports
 */
abstract class TenantMessengerTestCase extends KernelTestCase
{
    use WithTenantTrait;

    protected ?MessageBusInterface $messageBus = null;
    protected ?EntityManagerInterface $entityManager = null;
    protected ?TenantContextInterface $tenantContext = null;
    protected ?TenantRegistryInterface $tenantRegistry = null;
    protected ?TestData $testData = null;

    /** @var array<string, TransportInterface> */
    protected array $transports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = static::createKernel();
        $kernel->boot();

        $container = static::getContainer();

        $this->messageBus = $container->get('messenger.bus.default');
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->tenantRegistry = $container->get(TenantRegistryInterface::class);
        $this->testData = new TestData($this->entityManager, $this->tenantRegistry);

        $this->setupTestTransports($container);
    }

    protected function tearDown(): void
    {
        $this->testData?->clearAll();
        $this->tenantContext?->clear();
        $this->clearTransports();

        parent::tearDown();
    }

    /**
     * Dispatch a message with tenant context.
     *
     * @param object $message    The message to dispatch
     * @param string $tenantSlug The tenant slug
     * @param array  $stamps     Additional stamps
     *
     * @return Envelope The dispatched envelope
     */
    protected function dispatchWithTenant(object $message, string $tenantSlug, array $stamps = []): Envelope
    {
        return $this->withTenant($tenantSlug, function () use ($message, $stamps) {
            return $this->messageBus->dispatch($message, $stamps);
        });
    }

    /**
     * Dispatch a message and verify it contains a tenant stamp.
     *
     * @param object $message    The message to dispatch
     * @param string $tenantSlug The expected tenant slug
     * @param array  $stamps     Additional stamps
     *
     * @return Envelope The dispatched envelope
     */
    protected function dispatchAndAssertTenantStamp(object $message, string $tenantSlug, array $stamps = []): Envelope
    {
        $envelope = $this->dispatchWithTenant($message, $tenantSlug, $stamps);

        $this->assertEnvelopeHasTenantStamp($envelope, $tenantSlug);

        return $envelope;
    }

    /**
     * Get messages from a transport.
     *
     * @param string $transportName The transport name
     *
     * @return Envelope[] Array of envelopes
     */
    protected function getTransportMessages(string $transportName): array
    {
        $transport = $this->getTransport($transportName);

        if ($transport instanceof InMemoryTransport) {
            return $transport->getSent();
        }

        throw new \RuntimeException(sprintf('Transport "%s" is not an InMemoryTransport', $transportName));
    }

    /**
     * Get a specific transport.
     *
     * @param string $transportName The transport name
     *
     * @return TransportInterface The transport
     */
    protected function getTransport(string $transportName): TransportInterface
    {
        if (!isset($this->transports[$transportName])) {
            $container = static::getContainer();
            $this->transports[$transportName] = $container->get(sprintf('messenger.transport.%s', $transportName));
        }

        return $this->transports[$transportName];
    }

    /**
     * Clear all messages from a transport.
     *
     * @param string $transportName The transport name
     */
    protected function clearTransport(string $transportName): void
    {
        $transport = $this->getTransport($transportName);

        if ($transport instanceof InMemoryTransport) {
            $transport->reset();
        }
    }

    /**
     * Clear all messages from all transports.
     */
    protected function clearTransports(): void
    {
        foreach (array_keys($this->transports) as $transportName) {
            $this->clearTransport($transportName);
        }
    }

    /**
     * Assert that an envelope has a tenant stamp with the expected tenant ID.
     *
     * @param Envelope $envelope   The envelope to check
     * @param string   $tenantSlug The expected tenant slug
     */
    protected function assertEnvelopeHasTenantStamp(Envelope $envelope, string $tenantSlug): void
    {
        $tenantStamps = $envelope->all(TenantStamp::class);

        $this->assertNotEmpty($tenantStamps, 'Envelope should have a TenantStamp');

        /** @var TenantStamp $tenantStamp */
        $tenantStamp = $tenantStamps[0];

        // Resolve tenant to get ID for comparison
        $tenant = $this->getTenantRegistry()->findBySlug($tenantSlug);
        $this->assertNotNull($tenant, sprintf('Tenant with slug "%s" not found', $tenantSlug));

        $this->assertSame(
            (string) $tenant->getId(),
            $tenantStamp->getTenantId(),
            'TenantStamp should contain the correct tenant ID'
        );
    }

    /**
     * Assert that an envelope does not have a tenant stamp.
     *
     * @param Envelope $envelope The envelope to check
     */
    protected function assertEnvelopeHasNoTenantStamp(Envelope $envelope): void
    {
        $tenantStamps = $envelope->all(TenantStamp::class);

        $this->assertEmpty($tenantStamps, 'Envelope should not have a TenantStamp');
    }

    /**
     * Assert that an envelope has a specific stamp.
     *
     * @param Envelope $envelope   The envelope to check
     * @param string   $stampClass The stamp class name
     */
    protected function assertEnvelopeHasStamp(Envelope $envelope, string $stampClass): void
    {
        $stamps = $envelope->all($stampClass);

        $this->assertNotEmpty($stamps, sprintf('Envelope should have a %s stamp', $stampClass));
    }

    /**
     * Assert that an envelope does not have a specific stamp.
     *
     * @param Envelope $envelope   The envelope to check
     * @param string   $stampClass The stamp class name
     */
    protected function assertEnvelopeHasNoStamp(Envelope $envelope, string $stampClass): void
    {
        $stamps = $envelope->all($stampClass);

        $this->assertEmpty($stamps, sprintf('Envelope should not have a %s stamp', $stampClass));
    }

    /**
     * Assert that a transport has received a specific number of messages.
     *
     * @param string $transportName    The transport name
     * @param int    $expectedCount    The expected message count
     */
    protected function assertTransportMessageCount(string $transportName, int $expectedCount): void
    {
        $messages = $this->getTransportMessages($transportName);

        $this->assertCount(
            $expectedCount,
            $messages,
            sprintf('Transport "%s" should have %d messages', $transportName, $expectedCount)
        );
    }

    /**
     * Assert that a transport has no messages.
     *
     * @param string $transportName The transport name
     */
    protected function assertTransportIsEmpty(string $transportName): void
    {
        $this->assertTransportMessageCount($transportName, 0);
    }

    /**
     * Process messages from a transport with tenant context.
     *
     * @param string $transportName The transport name
     * @param string $tenantSlug    The tenant slug for processing context
     */
    protected function processMessagesWithTenant(string $transportName, string $tenantSlug): void
    {
        $this->withTenant($tenantSlug, function () use ($transportName) {
            $transport = $this->getTransport($transportName);

            if ($transport instanceof InMemoryTransport) {
                // Simulate message processing by getting and acknowledging messages
                $messages = $transport->get();
                foreach ($messages as $envelope) {
                    $transport->ack($envelope);
                }
            }
        });
    }

    protected function getTenantContext(): TenantContextInterface
    {
        if (!$this->tenantContext) {
            throw new \RuntimeException('TenantContext not initialized. Call setUp() first.');
        }

        return $this->tenantContext;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        if (!$this->entityManager) {
            throw new \RuntimeException('EntityManager not initialized. Call setUp() first.');
        }

        return $this->entityManager;
    }

    protected function getTenantRegistry(): TenantRegistryInterface
    {
        if (!$this->tenantRegistry) {
            throw new \RuntimeException('TenantRegistry not initialized. Call setUp() first.');
        }

        return $this->tenantRegistry;
    }

    protected function getTestData(): TestData
    {
        if (!$this->testData) {
            throw new \RuntimeException('TestData not initialized. Call setUp() first.');
        }

        return $this->testData;
    }

    protected function getMessageBus(): MessageBusInterface
    {
        if (!$this->messageBus) {
            throw new \RuntimeException('MessageBus not initialized. Call setUp() first.');
        }

        return $this->messageBus;
    }

    /**
     * Setup test transports from the container.
     *
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     */
    private function setupTestTransports($container): void
    {
        // Common transport names to look for
        $transportNames = ['async', 'sync', 'failed'];

        foreach ($transportNames as $transportName) {
            $serviceId = sprintf('messenger.transport.%s', $transportName);
            if ($container->has($serviceId)) {
                $this->transports[$transportName] = $container->get($serviceId);
            }
        }
    }
}