<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionDefaultStampsMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionGlobalMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionProbe;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\CompositionTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\MessengerCompositionKernel;

final class MessengerCompositionTest extends TestCase
{
    private MessengerCompositionKernel $kernel;
    private MessageBusInterface $bus;
    private TenantContextInterface $context;
    private CompositionProbe $probe;

    protected function tearDown(): void
    {
        CompositionProbe::$active = null;
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    private function boot(string $scenario = 'standard', ?string $environment = null): string
    {
        $environment ??= $scenario.'_'.bin2hex(random_bytes(8));
        $this->kernel = new MessengerCompositionKernel($environment, 'profiler' === $scenario);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->bus = $container->get('composition.injected_bus');
        $this->context = $container->get(TenantContextInterface::class);
        $this->probe = $container->get(CompositionProbe::class);
        $this->probe->bus = $this->bus;
        $this->probe->otherBus = 'implicit' === $scenario ? $this->bus : $container->get('other.bus');
        CompositionProbe::$active = $this->probe;
        $registry = $container->get(InMemoryTenantRegistry::class);
        foreach ([1 => 'tenant-a', 2 => 'tenant-b'] as $id => $slug) {
            $registry->addTenant((new TestTenant())->setId($id)->setSlug($slug));
        }

        return $environment;
    }

    public static function configurations(): iterable
    {
        foreach (['implicit', 'validation', 'standard', 'explicit', 'nodefaults', 'split', 'profiler'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('configurations')]
    public function testCompiledBusProtectsReceivedValidationAndPreservesConsumerChain(string $scenario): void
    {
        $environment = $this->boot($scenario);
        $container = $this->kernel->getContainer()->get('test.service_container');
        $chain = $container->getParameter('composition.messenger.bus.default');
        self::assertSame(1, count(array_keys($chain, TenantWorkerMiddleware::class, true)));
        self::assertSame(1, count(array_keys($chain, TenantSendingMiddleware::class, true)));
        if ('profiler' === $scenario) {
            self::assertInstanceOf(TraceableMessageBus::class, $this->bus);
        }
        $envelope = $this->bus->dispatch(new CompositionTenantMessage(), [new ReceivedStamp('async'), new TenantStamp('1')]);
        self::assertSame('messenger.bus.default', $envelope->last(BusNameStamp::class)?->getBusName());
        self::assertNull($this->context->getTenant());
        self::assertContains(['handler', 'normal', 1], $this->probe->events);
        if ('implicit' !== $scenario) {
            self::assertContains(['validation', 'normal', 1], $this->probe->events);
        }
        if (!in_array($scenario, ['implicit', 'validation'], true)) {
            self::assertSame([
                ['validation', 'normal', 1], ['one.before', 'normal', 1], ['two.before', 'normal', 1],
                ['handler', 'normal', 1], ['two.after', 'normal', 1], ['one.after', 'normal', 1],
            ], $this->probe->events);
        }
        // Boot the already dumped container; repeat composition is asserted in the capture pass.
        $this->kernel->shutdown();
        restore_exception_handler();
        $this->boot($scenario, $environment);
        $this->bus->dispatch(new CompositionTenantMessage(), [new ReceivedStamp('async'), new TenantStamp('2')]);
        self::assertContains(['handler', 'normal', 2], $this->probe->events);
        self::assertNull($this->context->getTenant());
    }

    public function testOutgoingValidationRoutingAndClassificationRemainFailClosed(): void
    {
        $this->boot();
        $this->context->setTenant((new TestTenant())->setId(1)->setSlug('tenant-a'));
        $transport = $this->kernel->getContainer()->get('test.service_container')->get('messenger.transport.other');
        $stamp = new TransportNamesStamp(['other']);
        $sent = $this->bus->dispatch(new CompositionTenantMessage(), [$stamp]);
        self::assertSame($stamp, $sent->last(TransportNamesStamp::class));
        self::assertSame('1', $sent->last(TenantStamp::class)?->getTenantId());
        self::assertCount(1, $transport->getSent());
        self::assertSame(['validation', 'one.before', 'two.before', 'two.after', 'one.after'], array_column($this->probe->events, 0));
        foreach ([
            [new \stdClass(), [], UnclassifiedMessageException::class],
            [new CompositionDoubleMessage(), [], UnclassifiedMessageException::class],
            [new CompositionTenantMessage(), [new TenantStamp('404')], TenantMismatchException::class],
            [new CompositionTenantMessage(), [new TenantStamp('1'), new TenantStamp('2')], TenantMismatchException::class],
            [new CompositionGlobalMessage(), [new TenantStamp('1')], TenantMismatchException::class],
            [new CompositionTenantMessage(''), [], ValidationFailedException::class],
        ] as [$message, $stamps, $exception]) {
            try {
                $this->bus->dispatch($message, [...$stamps, $stamp]);
                self::fail('Unsafe dispatch reached a transport.');
            } catch (\Throwable $failure) {
                self::assertInstanceOf($exception, $failure);
            }
        }
        self::assertCount(1, $transport->getSent());
        $this->context->reset();
        try {
            $this->bus->dispatch(new CompositionTenantMessage(), [$stamp]);
            self::fail('Missing tenant accepted.');
        } catch (MissingTenantContextException) {
            self::assertCount(1, $transport->getSent());
        }
        $this->bus->dispatch(new CompositionGlobalMessage());
        $async = $this->kernel->getContainer()->get('test.service_container')->get('messenger.transport.async');
        self::assertCount(1, $async->getSent());
        self::assertNull($async->getSent()[0]->last(TenantStamp::class));
    }

    public function testTenantTransportMappingIsUnchanged(): void
    {
        $this->boot('tenanttransport');
        $this->context->setTenant((new TestTenant())->setId(1)->setSlug('acme'));
        $envelope = $this->bus->dispatch(new CompositionTenantMessage());
        self::assertSame(['other'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
    }

    public static function nestedDispatches(): iterable
    {
        foreach (['delayed', 'nested', 'nested_other_bus'] as $action) {
            foreach (['standard', 'explicit', 'nodefaults', 'profiler'] as $scenario) {
                yield $action.' '.$scenario => [$action, $scenario];
            }
        }
    }

    #[DataProvider('nestedDispatches')]
    public function testNestedAndDeferredMessagesKeepContextUntilTheCompleteChainFinishes(string $action, string $scenario): void
    {
        $this->boot($scenario);
        $this->bus->dispatch(new CompositionTenantMessage($action), [new ReceivedStamp('async'), new TenantStamp('1')]);
        self::assertContains(['handler', 'child', 1], $this->probe->events);
        self::assertSame([1], array_values(array_unique(array_column($this->probe->events, 2))));
        self::assertCount(2, array_filter($this->probe->events, static fn (array $event): bool => 'validation' === $event[0]));
        if ('delayed' === $action) {
            self::assertGreaterThan(array_search(['one.after', $action, 1], $this->probe->events, true), array_search(['validation', 'child', 1], $this->probe->events, true));
        } else {
            self::assertContains(['parent.resumed', $action, 1], $this->probe->events);
        }
        self::assertNull($this->context->getTenant());
    }

    public function testExceptionsAndRejectedReceivePreparationAlwaysCleanContext(): void
    {
        $this->boot();
        foreach (['before_failure', 'failure', 'after_failure', 'delayed_failure', 'delayed_then_failure'] as $action) {
            $this->probe->events = [];
            try {
                $this->bus->dispatch(new CompositionTenantMessage($action), [new ReceivedStamp('async'), new TenantStamp('1')]);
                self::fail('Controlled exception did not occur.');
            } catch (\Exception $exception) {
                if ('delayed_failure' === $action) {
                    self::assertInstanceOf(DelayedMessageHandlingException::class, $exception);
                }
                self::assertNull($this->context->getTenant());
                self::assertSame([1], array_values(array_unique(array_column($this->probe->events, 2))));
            }
            if ('delayed_then_failure' === $action) {
                self::assertNotContains(['handler', 'child', 1], $this->probe->events);
            }
        }
        foreach ([[[], MissingTenantStampException::class], [[new TenantStamp('404')], UnknownTenantException::class], [[new TenantStamp('1'), new TenantStamp('2')], TenantMismatchException::class]] as [$stamps, $exception]) {
            $this->probe->events = [];
            try {
                $this->bus->dispatch(new CompositionTenantMessage(), [new ReceivedStamp('async'), ...$stamps]);
                self::fail('Invalid received tenant accepted.');
            } catch (\Throwable $failure) {
                self::assertInstanceOf($exception, $failure);
            }
            self::assertSame([], $this->probe->events);
            self::assertNull($this->context->getTenant());
        }
    }

    public function testRealWorkerSequenceUsesSerializationAndLeavesNoContext(): void
    {
        $this->boot();
        $transport = new InMemoryTransport(new PhpSerializer());
        foreach ([['normal', '1'], ['global', null], ['normal', '2'], ['failure', '2'], ['global', null], ['normal', '1']] as [$action, $tenant]) {
            $transport->send(new Envelope(null === $tenant ? new CompositionGlobalMessage() : new CompositionTenantMessage($action), null === $tenant ? [] : [new TenantStamp($tenant)]));
        }
        $after = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(6));
        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            $dispatcher->addListener($event, function () use (&$after): void { $after[] = $this->context->getTenant(); });
        }
        (new Worker(['async' => $transport], $this->bus, $dispatcher))->run(['sleep' => 0]);
        self::assertSame([null, null, null, null, null, null], $after);
        self::assertSame([1, null, 2, 2, null, 1], array_column(array_values(array_filter($this->probe->events, static fn (array $event): bool => 'handler' === $event[0])), 2));
        self::assertCount(5, $transport->getAcknowledged());
        self::assertCount(1, $transport->getRejected());
    }

    public function testFailureTransportReplayAndRedeliveryRetainTenantValidation(): void
    {
        $this->boot();
        $transport = new InMemoryTransport(new PhpSerializer());
        $failure = new InMemoryTransport(new PhpSerializer());
        $transport->send(new Envelope(new CompositionTenantMessage('failure'), [new TenantStamp('1')]));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(new ServiceLocator(['async' => static fn () => $transport]), new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(1, 0, 1, 0, 0)])));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn () => $failure])));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));
        (new Worker(['async' => $transport], $this->bus, $dispatcher))->run(['sleep' => 0]);
        self::assertNull($this->context->getTenant());
        self::assertCount(1, $failure->getSent());
        $envelope = iterator_to_array($failure->get())[0];
        self::assertInstanceOf(RedeliveryStamp::class, $envelope->last(RedeliveryStamp::class));
        self::assertInstanceOf(SentToFailureTransportStamp::class, $envelope->last(SentToFailureTransportStamp::class));
        $recovered = new Envelope(new CompositionTenantMessage('recovered'), array_merge(...array_values($envelope->all())));
        $this->probe->events = [];
        $this->bus->dispatch($recovered, [new ReceivedStamp('failed')]);
        self::assertContains(['validation', 'recovered', 1], $this->probe->events);
        self::assertContains(['handler', 'recovered', 1], $this->probe->events);
        self::assertNull($this->context->getTenant());
    }

    public function testDefaultStampsAndAvailableSymfonyDecoderRunBeforeClassification(): void
    {
        $this->boot();
        $this->bus->dispatch(new CompositionDefaultStampsMessage(), [new ReceivedStamp('async')]);
        self::assertContains(['handler', 'default', 1], $this->probe->events);
        if (class_exists(DecodeFailedMessageMiddleware::class)) {
            $encoded = (new PhpSerializer())->encode(new Envelope(new CompositionTenantMessage('decoded'), [new TenantStamp('2')]));
            $this->bus->dispatch(new MessageDecodingFailedException('historical decode failure', encodedEnvelope: $encoded), [new ReceivedStamp('failed'), new SentToFailureTransportStamp('async')]);
            self::assertContains(['validation', 'decoded', 2], $this->probe->events);
            self::assertContains(['handler', 'decoded', 2], $this->probe->events);
        }
        self::assertNull($this->context->getTenant());
    }

    public function testDisabledIntegrationPreservesSymfonyBehavior(): void
    {
        $this->boot('disabled');
        $chain = $this->kernel->getContainer()->getParameter('composition.messenger.bus.default');
        self::assertNotContains(TenantWorkerMiddleware::class, $chain);
        $envelope = $this->bus->dispatch(new \stdClass(), [new TransportNamesStamp(['async'])]);
        self::assertNull($envelope->last(TenantStamp::class));
    }
}

final class CompositionDoubleMessage implements TenantAwareMessageInterface, GlobalMessageInterface
{
}
