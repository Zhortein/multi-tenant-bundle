<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\RuntimeException as MessengerRuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\AttributeRoutedTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\ConfiguredAndAttributedTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\ConfiguredRouteTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\SynchronousTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\MessengerRoutingProbe;

final class MessengerFrameworkRoutingIntegrationTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel(['environment' => 'messenger_routing', 'debug' => false]);
        $this->bus = self::getContainer()->get('messenger.bus.default');
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->tenantContext->setTenant((new TestTenant())->setId(1)->setSlug('acme'));
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
        restore_exception_handler();
    }

    public function testYamlRouteSelectsNotificationsWithoutABundleTransportStamp(): void
    {
        $envelope = $this->bus->dispatch(new ConfiguredRouteTenantMessage());

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
        self::assertSame('1', $envelope->last(TenantStamp::class)?->getTenantId());
    }

    public function testAsMessageAttributeSelectsItsTransport(): void
    {
        $this->bus->dispatch(new AttributeRoutedTenantMessage());

        self::assertCount(1, $this->transport('attribute_transport')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
    }

    public function testYamlRouteTakesPriorityOverAsMessageAttribute(): void
    {
        $this->bus->dispatch(new ConfiguredAndAttributedTenantMessage());

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('attribute_transport')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
    }

    public function testExplicitTransportStampRemainsIntactAndTakesPriority(): void
    {
        $stamp = new TransportNamesStamp(['async']);

        $this->bus->dispatch(new ConfiguredRouteTenantMessage(), [$stamp]);

        self::assertCount(1, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertSame($stamp, $this->transport('async')->getSent()[0]->last(TransportNamesStamp::class));
    }

    public function testUnroutedMessageWithHandlerIsHandledSynchronouslyWithoutFallback(): void
    {
        $envelope = $this->bus->dispatch(new SynchronousTenantMessage());

        self::assertSame(1, self::getContainer()->get(MessengerRoutingProbe::class)->handledCount());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('attribute_transport')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    public function testUnknownExplicitAliasRaisesTheNativeSymfonyError(): void
    {
        $this->expectException(MessengerRuntimeException::class);
        $this->expectExceptionMessage('sender "missing" is not in the senders locator');

        $this->bus->dispatch(new ConfiguredRouteTenantMessage(), [new TransportNamesStamp(['missing'])]);
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
