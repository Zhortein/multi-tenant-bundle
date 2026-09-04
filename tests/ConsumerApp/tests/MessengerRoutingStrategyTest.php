<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use App\Message\AttributeTenantMessage;
use App\Message\ConfiguredAndAttributedTenantMessage;
use App\Message\SynchronousTenantMessage;
use App\Message\TenantMessage;
use App\Messenger\RoutingProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class MessengerRoutingStrategyTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->bus = self::getContainer()->get('messenger.bus.default');
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->tenantContext->setTenant(new Tenant());
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function testFrameworkRouteAndAttributeRoutingUseDistinctTransports(): void
    {
        $frameworkEnvelope = $this->bus->dispatch(new TenantMessage());
        $this->bus->dispatch(new AttributeTenantMessage());

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(1, $this->transport('attribute_transport')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertNull($frameworkEnvelope->last(TransportNamesStamp::class));
    }

    public function testFrameworkRouteTakesPriorityOverAttribute(): void
    {
        $this->bus->dispatch(new ConfiguredAndAttributedTenantMessage());

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('attribute_transport')->getSent());
    }

    public function testExplicitStampTakesPriorityAndRemainsIntact(): void
    {
        $stamp = new TransportNamesStamp(['async']);

        $this->bus->dispatch(new TenantMessage(), [$stamp]);

        self::assertCount(1, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertSame($stamp, $this->transport('async')->getSent()[0]->last(TransportNamesStamp::class));
    }

    public function testUnroutedMessageWithHandlerRunsSynchronouslyWithoutFallback(): void
    {
        $envelope = $this->bus->dispatch(new SynchronousTenantMessage());

        self::assertSame(1, self::getContainer()->get(RoutingProbe::class)->handledCount());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('attribute_transport')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
