<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Exception\RuntimeException as MessengerRuntimeException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Messenger\MessengerRoutingStrategy;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class MessengerNativeRoutingStrategyTest extends TestCase
{
    private InMemoryTransport $async;
    private InMemoryTransport $notifications;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->async = new InMemoryTransport();
        $this->notifications = new InMemoryTransport();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setTenant((new TestTenant())->setId(1)->setSlug('acme'));
    }

    public function testConfiguredRouteSelectsItsTransportInsteadOfTheBundleDefault(): void
    {
        $bus = $this->createBus([
            ConfiguredRouteTenantMessage::class => ['notifications'],
        ]);

        $envelope = $bus->dispatch(new ConfiguredRouteTenantMessage());

        self::assertCount(1, $this->notifications->getSent());
        self::assertCount(0, $this->async->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    public function testDefaultStrategyPreservesTenantTransportMapping(): void
    {
        $bus = $this->createTenantTransportBus(['acme' => 'async'], 'notifications');

        $bus->dispatch(new ConfiguredRouteTenantMessage());

        self::assertCount(1, $this->async->getSent());
        self::assertCount(0, $this->notifications->getSent());
    }

    public function testDefaultStrategyPreservesFallbackTransport(): void
    {
        $bus = $this->createTenantTransportBus([], 'async');

        $bus->dispatch(new ConfiguredRouteTenantMessage());

        self::assertCount(1, $this->async->getSent());
        self::assertCount(0, $this->notifications->getSent());
    }

    public function testAsMessageAttributeSelectsItsTransportInsteadOfTheBundleDefault(): void
    {
        $bus = $this->createBus([]);

        $bus->dispatch(new AttributeRoutedTenantMessage());

        self::assertCount(1, $this->notifications->getSent());
        self::assertCount(0, $this->async->getSent());
    }

    public function testConfiguredRouteTakesPriorityOverAsMessageAttribute(): void
    {
        $bus = $this->createBus([
            ConfiguredAndAttributedTenantMessage::class => ['notifications'],
        ]);

        $bus->dispatch(new ConfiguredAndAttributedTenantMessage());

        self::assertCount(1, $this->notifications->getSent());
        self::assertCount(0, $this->async->getSent());
    }

    public function testExplicitTransportStampIsPreservedAndTakesPriority(): void
    {
        $bus = $this->createBus([
            ConfiguredRouteTenantMessage::class => ['notifications'],
        ]);
        $stamp = new TransportNamesStamp(['async']);

        $bus->dispatch(new ConfiguredRouteTenantMessage(), [$stamp]);

        self::assertCount(0, $this->notifications->getSent());
        self::assertCount(1, $this->async->getSent());
        self::assertSame($stamp, $this->async->getSent()[0]->last(TransportNamesStamp::class));
    }

    public function testUnroutedMessageWithHandlerIsHandledSynchronously(): void
    {
        $handled = false;
        $bus = $this->createBus([], [
            SynchronousTenantMessage::class => [static function () use (&$handled): void {
                $handled = true;
            }],
        ]);

        $envelope = $bus->dispatch(new SynchronousTenantMessage());

        self::assertTrue($handled);
        self::assertCount(0, $this->notifications->getSent());
        self::assertCount(0, $this->async->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    public function testUnknownExplicitTransportAliasKeepsSymfonyFailure(): void
    {
        $bus = $this->createBus([]);

        $this->expectException(MessengerRuntimeException::class);
        $this->expectExceptionMessage('sender "missing" is not in the senders locator');

        $bus->dispatch(new ConfiguredRouteTenantMessage(), [new TransportNamesStamp(['missing'])]);
    }

    /**
     * @param array<string, list<string>> $sendersMap
     */
    private function createBus(array $sendersMap, array $handlers = []): MessageBus
    {
        $senders = new ServiceLocator([
            'async' => fn (): InMemoryTransport => $this->async,
            'notifications' => fn (): InMemoryTransport => $this->notifications,
        ]);

        $middleware = [
            new TenantSendingMiddleware($this->tenantContext),
            new TenantMessengerTransportResolver(
                $this->tenantContext,
                ['acme' => 'tenant_specific'],
                'fallback',
                true,
                MessengerRoutingStrategy::SYMFONY_ROUTING,
            ),
            new SendMessageMiddleware(new SendersLocator($sendersMap, $senders)),
        ];
        if ([] !== $handlers) {
            $middleware[] = new HandleMessageMiddleware(new HandlersLocator($handlers));
        }

        return new MessageBus($middleware);
    }

    /**
     * @param array<string, string> $tenantTransportMap
     */
    private function createTenantTransportBus(array $tenantTransportMap, string $defaultTransport): MessageBus
    {
        $senders = new ServiceLocator([
            'async' => fn (): InMemoryTransport => $this->async,
            'notifications' => fn (): InMemoryTransport => $this->notifications,
        ]);

        return new MessageBus([
            new TenantSendingMiddleware($this->tenantContext),
            new TenantMessengerTransportResolver($this->tenantContext, $tenantTransportMap, $defaultTransport),
            new SendMessageMiddleware(new SendersLocator([
                ConfiguredRouteTenantMessage::class => ['notifications'],
            ], $senders)),
        ]);
    }
}

final class ConfiguredRouteTenantMessage implements TenantAwareMessageInterface
{
}

#[AsMessage('notifications')]
final class AttributeRoutedTenantMessage implements TenantAwareMessageInterface
{
}

#[AsMessage('async')]
final class ConfiguredAndAttributedTenantMessage implements TenantAwareMessageInterface
{
}

final class SynchronousTenantMessage implements TenantAwareMessageInterface
{
}
