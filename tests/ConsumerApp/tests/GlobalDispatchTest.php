<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use App\Message\ScheduledGlobalMessage;
use App\Message\ScheduledTenantMessage;
use App\Messenger\ConsumerMiddlewareProbe;
use App\Scheduler\SchedulerProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

final class GlobalDispatchTest extends KernelTestCase
{
    private TenantContextInterface $context;
    private Connection $connection;
    private TransportInterface $transport;
    private SchedulerProbe $probe;
    private ConsumerMiddlewareProbe $middleware;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $container = self::getContainer();
        $this->tenantA = new Tenant('dispatch-a', 'dispatch-a');
        $this->tenantB = new Tenant('dispatch-b', 'dispatch-b');
        $container->set(TenantRegistryInterface::class, new InMemoryTenantRegistry([$this->tenantA, $this->tenantB]));
        $this->context = $container->get(TenantContextInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->transport = $container->get('messenger.transport.scheduler_persistent');
        $this->probe = $container->get(SchedulerProbe::class);
        $this->middleware = $container->get(ConsumerMiddlewareProbe::class);
    }

    public static function dispatchPaths(): iterable
    {
        foreach (['messenger.bus.default', 'secondary.bus'] as $bus) {
            foreach ([false, true] as $wrapped) {
                foreach ([false, true] as $failure) {
                    yield $bus.' wrapped='.(int) $wrapped.' failure='.(int) $failure => [$bus, $wrapped, $failure];
                }
            }
        }
    }

    #[DataProvider('dispatchPaths')]
    public function testTenantGlobalSequenceIsPersistedAndHandledWithoutContextLeak(string $busId, bool $wrapped, bool $failure): void
    {
        $bus = self::getContainer()->get($busId);
        $lastLabel = $failure ? 'consumer-scheduler-failure' : 'global-b';
        foreach ([
            [$this->tenantA, new ScheduledTenantMessage('tenant-a'), 'dispatch-a'],
            [$this->tenantA, new ScheduledGlobalMessage('global-a'), null],
            [$this->tenantB, new ScheduledTenantMessage('tenant-b'), 'dispatch-b'],
            [$this->tenantB, new ScheduledGlobalMessage($lastLabel), null],
        ] as [$tenant, $message, $expectedStamp]) {
            $this->context->setTenant($tenant);
            $envelope = $wrapped
                ? $bus->dispatch(new RedispatchMessage($message, 'scheduler_persistent'))
                : $bus->dispatch($message, [new TransportNamesStamp(['scheduler_persistent'])]);
            self::assertSame($expectedStamp, $envelope->last(TenantStamp::class)?->getTenantId());
            self::assertSame($tenant, $this->context->getTenant());
        }
        self::assertSame([], $this->probe->handled());
        self::assertSame(4, $this->pendingRows());
        $failures = $this->runWorker($this->transport, $bus, 4);
        self::assertCount($failure ? 1 : 0, $failures);
        if ($failure) {
            self::assertStringContainsString('Controlled application Worker failure.', $failures[0]->getMessage());
        }
        self::assertSame([
            ['tenant-a', 'dispatch-a'], ['global-a', null], ['tenant-b', 'dispatch-b'], [$lastLabel, null],
        ], $this->probe->handled());
        self::assertSame(0, $this->pendingRows());
        self::assertNull($this->context->getTenant());
    }

    public static function schedulerPaths(): iterable
    {
        foreach (['messenger.bus.default', 'secondary.bus'] as $bus) {
            foreach ([false, true] as $tenantAware) {
                yield $bus.' tenant='.(int) $tenantAware => [$bus, $tenantAware];
            }
        }
    }

    #[DataProvider('schedulerPaths')]
    public function testSchedulerPersistsEachClassificationBeforeApplicationWorker(string $busId, bool $tenantAware): void
    {
        $bus = self::getContainer()->get($busId);
        $message = $tenantAware ? new ScheduledTenantMessage('scheduled') : new ScheduledGlobalMessage('scheduled');
        $envelope = new Envelope($message, $tenantAware ? [new TenantStamp('dispatch-a')] : []);
        $clock = new MockClock('2026-09-05 12:00:00 UTC');
        $schedule = (new Schedule())->add(RecurringMessage::every(1, new RedispatchMessage($envelope, 'scheduler_persistent')));
        $generator = new MessageGenerator($schedule, 'global_dispatch', $clock);
        iterator_to_array($generator->getMessages());
        $clock->modify('+1 second');
        $this->context->setTenant($this->tenantB);
        self::assertSame([], $this->runWorker(new SchedulerTransport($generator), $bus, 1));
        self::assertSame([], $this->probe->handled());
        self::assertSame(1, $this->pendingRows());
        self::assertNull($this->context->getTenant());
        $this->context->setTenant($this->tenantB);
        self::assertSame([], $this->runWorker($this->transport, $bus, 1));
        self::assertSame([['scheduled', $tenantAware ? 'dispatch-a' : null]], $this->probe->handled());
        self::assertSame(0, $this->pendingRows());
    }

    public static function invalidDispatches(): iterable
    {
        foreach (['messenger.bus.default', 'secondary.bus'] as $bus) {
            yield $bus.' global stamp' => [$bus, new Envelope(new ScheduledGlobalMessage('forbidden'), [new TenantStamp('dispatch-a')]), TenantMismatchException::class, true];
            yield $bus.' wrapped global stamp' => [$bus, new Envelope(new RedispatchMessage(new Envelope(new ScheduledGlobalMessage('forbidden'), [new TenantStamp('dispatch-a')]), 'scheduler_persistent')), TenantMismatchException::class, true];
            yield $bus.' foreign tenant' => [$bus, new Envelope(new ScheduledTenantMessage('forbidden'), [new TenantStamp('dispatch-b')]), TenantMismatchException::class, true];
            yield $bus.' no context' => [$bus, new Envelope(new ScheduledTenantMessage('forbidden')), MissingTenantContextException::class, false];
            yield $bus.' unclassified' => [$bus, new Envelope(new \stdClass()), UnclassifiedMessageException::class, true];
            yield $bus.' double classification' => [$bus, new Envelope(new DoublyClassifiedDispatch()), UnclassifiedMessageException::class, true];
        }
    }

    #[DataProvider('invalidDispatches')]
    public function testInvalidSendNeverReachesApplicationMiddlewareOrHandler(string $busId, Envelope $envelope, string $exception, bool $active): void
    {
        if ($active) {
            $this->context->setTenant($this->tenantA);
        } else {
            $this->context->reset();
        }
        try {
            self::getContainer()->get($busId)->dispatch($envelope, [new TransportNamesStamp(['scheduler_persistent'])]);
            self::fail('Invalid message reached sending.');
        } catch (\Throwable $failure) {
            self::assertInstanceOf($exception, $failure);
        }
        self::assertSame([], $this->probe->handled());
        self::assertSame([], $this->middleware->events);
        self::assertSame($active ? $this->tenantA : null, $this->context->getTenant());
    }

    public function testInvalidPersistedEnvelopesFailBeforeBusinessEffectsAndCleanContext(): void
    {
        $bus = self::getContainer()->get('messenger.bus.default');
        foreach ([
            [new Envelope(new ScheduledGlobalMessage('forbidden'), [new TenantStamp('dispatch-a')]), TenantMismatchException::class],
            [new Envelope(new ScheduledTenantMessage('forbidden')), MissingTenantStampException::class],
            [new Envelope(new ScheduledTenantMessage('forbidden'), [new TenantStamp('unknown')]), UnknownTenantException::class],
            [new Envelope(new ScheduledTenantMessage('forbidden'), [new TenantStamp('dispatch-a'), new TenantStamp('dispatch-b')]), TenantMismatchException::class],
            [new Envelope(new \stdClass()), UnclassifiedMessageException::class],
            [new Envelope(new DoublyClassifiedDispatch()), UnclassifiedMessageException::class],
        ] as [$envelope, $exception]) {
            $this->transport->send($envelope);
            self::assertSame(1, $this->pendingRows());
            $this->context->setTenant($this->tenantB);
            $failures = $this->runWorker($this->transport, $bus, 1);
            self::assertCount(1, $failures);
            self::assertInstanceOf($exception, $failures[0]);
            self::assertSame([], $this->probe->handled());
            self::assertSame([], $this->middleware->events);
            self::assertSame(0, $this->pendingRows());
        }
    }

    private function pendingRows(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'scheduler_rc9'");
    }

    /** @return list<\Throwable> */
    private function runWorker(TransportInterface $transport, MessageBusInterface $bus, int $limit): array
    {
        $failures = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable();
        });
        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            $dispatcher->addListener($event, function (): void {
                self::assertNull($this->context->getTenant());
            });
        }
        (new Worker(['proof' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        return $failures;
    }
}

final class DoublyClassifiedDispatch implements TenantAwareMessageInterface, GlobalMessageInterface
{
}
