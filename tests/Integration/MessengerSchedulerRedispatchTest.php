<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\RedispatchMessageHandler;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\MessengerRoutingStrategy;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class MessengerSchedulerRedispatchTest extends TestCase
{
    #[DataProvider('routingStrategies')]
    public function testRealSchedulerRedispatchesTenantMessageAcrossSerializationWithoutRunningItsHandler(MessengerRoutingStrategy $routingStrategy): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $bundleFallback = new SerializedTestTransport();
        $handled = [];
        $handlers = [
            ScheduledTenantMessage::class => [static function (ScheduledTenantMessage $message) use ($context, &$handled): void {
                $handled[] = [
                    $message->label,
                    $context->getTenant()?->getSlug(),
                ];
            }],
        ];
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            ['persistent' => $persistent, 'bundle_fallback' => $bundleFallback],
            $handlers,
            $routingStrategy,
        );
        $applicationEnvelope = new Envelope(new ScheduledTenantMessage('nominal'), [
            new TenantStamp('1'),
            new LocaleMetadataStamp('fr_FR'),
            new SafeMetadataStamp('correlation-42'),
        ]);
        [$scheduler, $clock] = $this->dueScheduler([
            new RedispatchMessage($applicationEnvelope, 'persistent'),
        ]);

        $schedulerRun = $this->runWorker($scheduler, $bus, 1, $context);

        self::assertSame(1, $schedulerRun->received);
        self::assertSame([], $schedulerRun->failures);
        self::assertSame([], $handled, 'The application handler must not run in the Scheduler Worker.');
        self::assertSame(1, $persistent->pendingCount());
        self::assertSame(0, $bundleFallback->pendingCount(), 'The RC8 bundle fallback must not intercept redispatch.');
        self::assertNull($context->getTenant());
        $persistedEnvelope = $persistent->peek();
        self::assertInstanceOf(ScheduledStamp::class, $persistedEnvelope->last(ScheduledStamp::class));
        self::assertSame('fr_FR', $persistedEnvelope->last(LocaleMetadataStamp::class)?->locale);
        self::assertSame('correlation-42', $persistedEnvelope->last(SafeMetadataStamp::class)?->value);

        $applicationRun = $this->runWorker($persistent, $bus, 1, $context);

        self::assertSame([], $applicationRun->failures);
        self::assertSame([['nominal', 'tenant-a']], $handled);
        self::assertSame(1, $persistent->acknowledgedCount());
        self::assertNull($context->getTenant());
        self::assertSame('2026-09-04 12:00:01.000000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function testGlobalAndTenantSequenceAlwaysReturnsBothWorkersToNone(): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $observed = [];
        $handlers = [
            ScheduledTenantMessage::class => [static function (ScheduledTenantMessage $message) use ($context, &$observed): void {
                $observed[] = [$message->label, $context->getTenant()?->getSlug()];
                if ('tenant-b-failure' === $message->label) {
                    throw new \RuntimeException('controlled application failure');
                }
            }],
            ScheduledGlobalMessage::class => [static function (ScheduledGlobalMessage $message) use ($context, &$observed): void {
                $observed[] = [$message->label, $context->getTenant()?->getSlug()];
            }],
        ];
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a'), $this->tenant(2, 'tenant-b')]),
            ['persistent' => $persistent],
            $handlers,
        );
        [$scheduler] = $this->dueScheduler([
            $this->tenantRedispatch('tenant-a-first', '1'),
            new RedispatchMessage(new ScheduledGlobalMessage('global-first'), 'persistent'),
            $this->tenantRedispatch('tenant-b-failure', '2'),
            new RedispatchMessage(new ScheduledGlobalMessage('global-second'), 'persistent'),
            $this->tenantRedispatch('tenant-a-last', '1'),
        ]);

        $schedulerRun = $this->runWorker($scheduler, $bus, 5, $context);
        self::assertSame([], $schedulerRun->failures);
        self::assertSame(array_fill(0, 5, null), $schedulerRun->contextsAfterBoundary);
        self::assertSame([], $observed);
        self::assertSame(5, $persistent->pendingCount());

        $applicationRun = $this->runWorker($persistent, $bus, 5, $context);

        self::assertCount(1, $applicationRun->failures);
        self::assertSame('controlled application failure', $applicationRun->failures[0]->getPrevious()?->getMessage());
        self::assertSame(array_fill(0, 5, null), $applicationRun->contextsAfterBoundary);
        self::assertSame([
            ['tenant-a-first', 'tenant-a'],
            ['global-first', null],
            ['tenant-b-failure', 'tenant-b'],
            ['global-second', null],
            ['tenant-a-last', 'tenant-a'],
        ], $observed);
        self::assertSame(4, $persistent->acknowledgedCount());
        self::assertSame(1, $persistent->rejectedCount());
        self::assertNull($context->getTenant());
    }

    public function testNestedRedispatchRemainsBoundedAndPreservesEachExplicitDestination(): void
    {
        $context = new TenantContext();
        $intermediate = new SerializedTestTransport();
        $persistent = new SerializedTestTransport();
        $handled = false;
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            ['intermediate' => $intermediate, 'persistent' => $persistent],
            [ScheduledTenantMessage::class => [static function () use (&$handled): void {
                $handled = true;
            }]],
        );
        $nested = new RedispatchMessage(
            new Envelope(
                new RedispatchMessage(
                    new Envelope(new ScheduledTenantMessage('nested'), [new TenantStamp('1')]),
                    'persistent',
                ),
            ),
            'intermediate',
        );
        [$scheduler] = $this->dueScheduler([$nested]);

        self::assertSame([], $this->runWorker($scheduler, $bus, 1, $context)->failures);
        self::assertFalse($handled);
        self::assertSame(1, $intermediate->pendingCount());
        self::assertSame(0, $persistent->pendingCount());

        self::assertSame([], $this->runWorker($intermediate, $bus, 1, $context)->failures);
        self::assertFalse($handled);
        self::assertSame(1, $persistent->pendingCount());

        self::assertSame([], $this->runWorker($persistent, $bus, 1, $context)->failures);
        self::assertTrue($handled);
        self::assertNull($context->getTenant());
    }

    /**
     * @param class-string<\Throwable> $expectedFailure
     */
    #[DataProvider('invalidScheduledMessages')]
    public function testInvalidScheduledStructuresAreRejectedBeforeRedispatch(object $scheduledMessage, string $expectedFailure): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $handled = false;
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            ['persistent' => $persistent],
            ['*' => [static function () use (&$handled): void {
                $handled = true;
            }]],
        );
        [$scheduler] = $this->dueScheduler([$scheduledMessage]);

        $run = $this->runWorker($scheduler, $bus, 1, $context);

        self::assertCount(1, $run->failures);
        self::assertInstanceOf($expectedFailure, $run->failures[0]);
        self::assertSame(0, $persistent->pendingCount());
        self::assertFalse($handled);
        self::assertNull($context->getTenant());
    }

    public static function invalidScheduledMessages(): iterable
    {
        yield 'unclassified inner message' => [new RedispatchMessage(new \stdClass(), 'persistent'), UnclassifiedMessageException::class];
        yield 'doubly classified inner message' => [new RedispatchMessage(new DoublyClassifiedScheduledMessage(), 'persistent'), UnclassifiedMessageException::class];
        yield 'tenant message without stamp' => [new RedispatchMessage(new ScheduledTenantMessage('missing-stamp'), 'persistent'), MissingTenantStampException::class];
        yield 'unknown tenant' => [new RedispatchMessage(new Envelope(new ScheduledTenantMessage('unknown'), [new TenantStamp('404')]), 'persistent'), UnknownTenantException::class];
        yield 'contradictory inner tenant stamps' => [new RedispatchMessage(new Envelope(new ScheduledTenantMessage('contradictory'), [new TenantStamp('1'), new TenantStamp('2')]), 'persistent'), TenantMismatchException::class];
        yield 'global inner message with tenant stamp' => [new RedispatchMessage(new Envelope(new ScheduledGlobalMessage('stamped-global'), [new TenantStamp('1')]), 'persistent'), TenantMismatchException::class];
        yield 'received inner envelope' => [new RedispatchMessage(new Envelope(new ScheduledGlobalMessage('already-received'), [new ReceivedStamp('forged')]), 'persistent'), UnclassifiedMessageException::class];
        yield 'missing redispatch destination' => [new RedispatchMessage(new ScheduledGlobalMessage('missing-destination')), UnclassifiedMessageException::class];
        yield 'invalid redispatch destination' => [new RedispatchMessage(new ScheduledGlobalMessage('invalid-destination'), [42]), UnclassifiedMessageException::class];
        yield 'unknown redispatch destination' => [new RedispatchMessage(new ScheduledGlobalMessage('unknown-destination'), 'unknown'), \RuntimeException::class];
        yield 'unsupported Symfony technical message' => [new RunCommandMessage('about'), UnclassifiedMessageException::class];
        yield 'excessive redispatch depth' => [self::nestedRedispatch(9), UnclassifiedMessageException::class];
        yield 'cyclic redispatch structure' => [self::cyclicRedispatch(), UnclassifiedMessageException::class];
    }

    public function testContradictoryOuterAndInnerTenantStampsAreRejected(): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a'), $this->tenant(2, 'tenant-b')]),
            ['persistent' => $persistent],
            [],
        );
        [$scheduler] = $this->dueScheduler([
            new RedispatchMessage(new Envelope(new ScheduledTenantMessage('contradictory'), [new TenantStamp('1')]), 'persistent'),
        ]);
        $tampered = new TamperingReceiver($scheduler, new TenantStamp('2'));

        $run = $this->runWorker($tampered, $bus, 1, $context);

        self::assertCount(1, $run->failures);
        self::assertInstanceOf(TenantMismatchException::class, $run->failures[0]);
        self::assertSame(0, $persistent->pendingCount());
        self::assertNull($context->getTenant());
    }

    public function testArtificialScheduledStampDoesNotAuthorizeAnUnclassifiedMessage(): void
    {
        $context = new TenantContext();
        $transport = new SerializedTestTransport();
        $bus = $this->createBus($context, new InMemoryTenantRegistry(), [], []);
        $trigger = new PeriodicalTrigger(1);
        $messageContext = new MessageContext(
            'forged',
            'forged-id',
            $trigger,
            new \DateTimeImmutable('2026-09-04 12:00:00 UTC'),
        );
        $transport->send(new Envelope(new \stdClass(), [new ScheduledStamp($messageContext)]));

        $run = $this->runWorker($transport, $bus, 1, $context);

        self::assertCount(1, $run->failures);
        self::assertInstanceOf(UnclassifiedMessageException::class, $run->failures[0]);
        self::assertNull($context->getTenant());
    }

    public function testUnreadableRedispatchPayloadIsRejectedExplicitly(): void
    {
        $context = new TenantContext();
        $transport = new SerializedTestTransport();
        $bus = $this->createBus($context, new InMemoryTenantRegistry(), [], []);
        $transport->send(new Envelope(self::unreadableRedispatch()));

        $run = $this->runWorker($transport, $bus, 1, $context);

        self::assertCount(1, $run->failures);
        self::assertInstanceOf(UnclassifiedMessageException::class, $run->failures[0]);
        self::assertStringContainsString('readable message or Envelope', $run->failures[0]->getMessage());
        self::assertNull($context->getTenant());
    }

    public function testNonSerializableInnerMessageFailsExplicitlyAndCleansContext(): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            ['persistent' => $persistent],
            [],
        );
        $message = new NonSerializableScheduledMessage(static fn (): bool => true);
        [$scheduler] = $this->dueScheduler([
            new RedispatchMessage(new Envelope($message, [new TenantStamp('1')]), 'persistent'),
        ]);

        $run = $this->runWorker($scheduler, $bus, 1, $context);

        self::assertCount(1, $run->failures);
        self::assertStringContainsString('Serialization of', $run->failures[0]->getPrevious()?->getMessage() ?? $run->failures[0]->getMessage());
        self::assertSame(0, $persistent->pendingCount());
        self::assertNull($context->getTenant());
    }

    public function testRetryAndRedeliveryRevalidateTenantAndPreserveCleanup(): void
    {
        $context = new TenantContext();
        $transport = new SerializedTestTransport();
        $attempts = 0;
        $contexts = [];
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            [],
            [ScheduledTenantMessage::class => [static function () use ($context, &$attempts, &$contexts): void {
                ++$attempts;
                $contexts[] = $context->getTenant()?->getSlug();
                if (1 === $attempts) {
                    throw new \RuntimeException('retry me');
                }
            }]],
        );
        $transport->send(new Envelope(new ScheduledTenantMessage('retry'), [new TenantStamp('1')]));
        $retryListener = new SendFailedMessageForRetryListener(
            new ServiceLocator(['test' => static fn (): TransportInterface => $transport]),
            new ServiceLocator(['test' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(1, 0, 1, 0, 0)]),
        );

        $run = $this->runWorker($transport, $bus, 2, $context, [$retryListener]);

        self::assertCount(1, $run->failures);
        self::assertSame('retry me', $run->failures[0]->getPrevious()?->getMessage());
        self::assertSame([true], $run->retryDecisions);
        self::assertSame(['tenant-a', 'tenant-a'], $contexts);
        self::assertSame([null, null], $run->contextsAfterBoundary);
        self::assertSame(0, $transport->pendingCount());
        self::assertSame(1, $transport->acknowledgedCount());
        self::assertSame(1, $transport->rejectedCount());
        self::assertNull($context->getTenant());
    }

    public function testApplicationFailureStillReachesSymfonyFailureTransport(): void
    {
        $context = new TenantContext();
        $persistent = new SerializedTestTransport();
        $failure = new SerializedTestTransport();
        $bus = $this->createBus(
            $context,
            new InMemoryTenantRegistry([$this->tenant(1, 'tenant-a')]),
            ['persistent' => $persistent],
            [ScheduledTenantMessage::class => [static function (): never {
                throw new \RuntimeException('send to failure transport');
            }]],
        );
        [$scheduler] = $this->dueScheduler([$this->tenantRedispatch('failure-transport', '1')]);

        self::assertSame([], $this->runWorker($scheduler, $bus, 1, $context)->failures);
        $failureListener = new SendFailedMessageToFailureTransportListener(new ServiceLocator([
            'test' => static fn (): TransportInterface => $failure,
        ]));
        $run = $this->runWorker($persistent, $bus, 1, $context, [$failureListener]);

        self::assertCount(1, $run->failures);
        self::assertSame(1, $persistent->rejectedCount());
        self::assertSame(1, $failure->pendingCount());
        self::assertInstanceOf(SentToFailureTransportStamp::class, $failure->peek()->last(SentToFailureTransportStamp::class));
        self::assertNull($context->getTenant());
    }

    public static function routingStrategies(): iterable
    {
        yield 'Symfony routing' => [MessengerRoutingStrategy::SYMFONY_ROUTING];
        yield 'tenant transport' => [MessengerRoutingStrategy::TENANT_TRANSPORT];
    }

    private function createBus(
        TenantContext $context,
        InMemoryTenantRegistry $registry,
        array $transports,
        array $applicationHandlers,
        MessengerRoutingStrategy $routingStrategy = MessengerRoutingStrategy::SYMFONY_ROUTING,
    ): MessageBus {
        $bus = null;
        $handlerMap = [
            RedispatchMessage::class => [static function (RedispatchMessage $message) use (&$bus): mixed {
                return (new RedispatchMessageHandler($bus))($message);
            }],
        ];
        foreach ($applicationHandlers as $messageClass => $handlers) {
            $handlerMap[$messageClass] = $handlers;
        }

        $factories = [];
        foreach ($transports as $name => $transport) {
            $factories[$name] = static fn (): TransportInterface => $transport;
        }
        $senders = new ServiceLocator($factories);
        $bus = new MessageBus([
            new TenantWorkerMiddleware($context, $registry),
            new TenantSendingMiddleware($context),
            new TenantMessengerTransportResolver(
                $context,
                ['tenant-a' => 'bundle_fallback'],
                'bundle_fallback',
                true,
                $routingStrategy,
            ),
            new SendMessageMiddleware(new SendersLocator([], $senders)),
            new HandleMessageMiddleware(new HandlersLocator($handlerMap)),
        ]);

        return $bus;
    }

    /**
     * @param list<object> $messages
     *
     * @return array{SchedulerTransport, MockClock}
     */
    private function dueScheduler(array $messages): array
    {
        $clock = new MockClock('2026-09-04 12:00:00 UTC');
        $schedule = new Schedule();
        foreach ($messages as $index => $message) {
            $schedule->add(RecurringMessage::trigger(
                new PeriodicalTrigger(1),
                new FixedMessageProvider($message, 'message-'.(string) $index),
            ));
        }
        $generator = new MessageGenerator($schedule, 'security-regression', $clock);
        iterator_to_array($generator->getMessages());
        $clock->modify('+1 second');

        return [new SchedulerTransport($generator), $clock];
    }

    /**
     * @param list<\Symfony\Component\EventDispatcher\EventSubscriberInterface> $subscribers
     */
    private function runWorker(ReceiverInterface $receiver, MessageBus $bus, int $limit, TenantContext $context, array $subscribers = []): WorkerRunResult
    {
        $result = new WorkerRunResult();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit));
        foreach ($subscribers as $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function () use ($result): void {
            ++$result->received;
        });
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function () use ($result, $context): void {
            $result->contextsAfterBoundary[] = $context->getTenant()?->getSlug();
        });
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($result, $context): void {
            $result->failures[] = $event->getThrowable();
            $result->retryDecisions[] = $event->willRetry();
            $result->contextsAfterBoundary[] = $context->getTenant()?->getSlug();
        });

        (new Worker(['test' => $receiver], $bus, $dispatcher))->run(['sleep' => 0]);

        return $result;
    }

    private function tenant(int $id, string $slug): TestTenant
    {
        return (new TestTenant())->setId($id)->setSlug($slug)->setName($slug);
    }

    private function tenantRedispatch(string $label, string $tenantId): RedispatchMessage
    {
        return new RedispatchMessage(
            new Envelope(new ScheduledTenantMessage($label), [new TenantStamp($tenantId)]),
            'persistent',
        );
    }

    private static function nestedRedispatch(int $depth): object
    {
        $message = new ScheduledGlobalMessage('nested-depth');
        for ($i = 0; $i < $depth; ++$i) {
            $message = new RedispatchMessage($message, 'persistent');
        }

        return $message;
    }

    private static function cyclicRedispatch(): RedispatchMessage
    {
        $class = RedispatchMessage::class;
        $serialized = sprintf(
            'O:%d:"%s":2:{s:8:"envelope";r:1;s:14:"transportNames";s:10:"persistent";}',
            strlen($class),
            $class,
        );
        $message = unserialize($serialized, ['allowed_classes' => [$class]]);
        if (!$message instanceof RedispatchMessage) {
            throw new \LogicException('Unable to construct the cyclic RedispatchMessage fixture.');
        }

        return $message;
    }

    private static function unreadableRedispatch(): RedispatchMessage
    {
        $class = RedispatchMessage::class;
        $serialized = sprintf(
            'O:%d:"%s":1:{s:14:"transportNames";s:10:"persistent";}',
            strlen($class),
            $class,
        );
        $message = unserialize($serialized, ['allowed_classes' => [$class]]);
        if (!$message instanceof RedispatchMessage) {
            throw new \LogicException('Unable to construct the unreadable RedispatchMessage fixture.');
        }

        return $message;
    }
}

final readonly class ScheduledTenantMessage implements TenantAwareMessageInterface
{
    public function __construct(public string $label)
    {
    }
}

final readonly class ScheduledGlobalMessage implements GlobalMessageInterface
{
    public function __construct(public string $label)
    {
    }
}

final class DoublyClassifiedScheduledMessage implements TenantAwareMessageInterface, GlobalMessageInterface
{
}

final readonly class NonSerializableScheduledMessage implements TenantAwareMessageInterface
{
    public function __construct(public \Closure $callback)
    {
    }
}

final readonly class LocaleMetadataStamp implements StampInterface
{
    public function __construct(public string $locale)
    {
    }
}

final readonly class SafeMetadataStamp implements StampInterface
{
    public function __construct(public string $value)
    {
    }
}

final class WorkerRunResult
{
    public int $received = 0;

    /** @var list<\Throwable> */
    public array $failures = [];

    /** @var list<string|null> */
    public array $contextsAfterBoundary = [];

    /** @var list<bool> */
    public array $retryDecisions = [];
}

final readonly class FixedMessageProvider implements MessageProviderInterface
{
    public function __construct(
        private object $message,
        private string $id,
    ) {
    }

    public function getMessages(MessageContext $context): iterable
    {
        yield $this->message;
    }

    public function getId(): string
    {
        return $this->id;
    }
}

final class SerializedTestTransport implements TransportInterface
{
    /** @var list<array{body: string, headers?: array<string, mixed>}> */
    private array $pending = [];
    private int $acknowledged = 0;
    private int $rejected = 0;

    public function __construct(private readonly SerializerInterface $serializer = new PhpSerializer())
    {
    }

    public function get(): iterable
    {
        if ([] === $this->pending) {
            return;
        }

        $encodedEnvelope = array_shift($this->pending);
        yield $this->serializer->decode($encodedEnvelope);
    }

    public function ack(Envelope $envelope): void
    {
        ++$this->acknowledged;
    }

    public function reject(Envelope $envelope): void
    {
        ++$this->rejected;
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->pending[] = $this->serializer->encode($envelope);

        return $envelope;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function peek(): Envelope
    {
        if ([] === $this->pending) {
            throw new \LogicException('No persisted envelope is available.');
        }

        return $this->serializer->decode($this->pending[0]);
    }

    public function acknowledgedCount(): int
    {
        return $this->acknowledged;
    }

    public function rejectedCount(): int
    {
        return $this->rejected;
    }
}

final readonly class TamperingReceiver implements ReceiverInterface
{
    public function __construct(
        private ReceiverInterface $inner,
        private StampInterface $stamp,
    ) {
    }

    public function get(): iterable
    {
        foreach ($this->inner->get() as $envelope) {
            yield $envelope->with($this->stamp);
        }
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }
}
