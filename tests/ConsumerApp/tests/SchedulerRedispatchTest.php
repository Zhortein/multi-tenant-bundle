<?php

declare(strict_types=1);

namespace App\Tests;

use App\Message\ScheduledGlobalMessage;
use App\Messenger\ConsumerMiddlewareProbe;
use App\Scheduler\SchedulerProbe;
use App\Scheduler\TenantSafeScheduleProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Validator\Constraints\Callback;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class SchedulerRedispatchTest extends KernelTestCase
{
    public static function outcomes(): iterable
    {
        yield 'success' => [false];
        yield 'handler exception' => [true];
    }

    #[DataProvider('outcomes')]
    public function testSchedulerWorkerOnlyPersistsAndApplicationWorkerHandlesLater(bool $failure): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $context = $container->get(TenantContextInterface::class);
        self::assertInstanceOf(TenantContextInterface::class, $context);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $transport = $container->get('messenger.transport.scheduler_persistent');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $bus = $container->get('messenger.bus.default');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $provider = $container->get(TenantSafeScheduleProvider::class);
        self::assertInstanceOf(TenantSafeScheduleProvider::class, $provider);
        $probe = $container->get(SchedulerProbe::class);
        self::assertInstanceOf(SchedulerProbe::class, $probe);
        $middlewareProbe = $container->get(ConsumerMiddlewareProbe::class);
        $validator = $container->get('validator');
        foreach ([RedispatchMessage::class, ScheduledGlobalMessage::class] as $class) {
            $validator->getMetadataFor($class)->addConstraint(new Callback(static function (object $message) use ($middlewareProbe): void {
                $middlewareProbe->record('validation', $message);
            }));
        }

        $clock = new MockClock('2026-09-04 12:00:00 UTC');
        $label = $failure ? 'consumer-scheduler-failure' : 'consumer-scheduler-proof';
        $schedule = $failure ? (new Schedule())->add(RecurringMessage::every(1, new RedispatchMessage(new ScheduledGlobalMessage($label), 'scheduler_persistent'))) : $provider;
        $generator = new MessageGenerator($schedule, 'tenant_safe', $clock);
        iterator_to_array($generator->getMessages());
        $clock->modify('+1 second');
        $scheduler = new SchedulerTransport($generator);

        self::assertSame([], $this->runOne($scheduler, $bus));
        self::assertSame([], $probe->handled(), 'The business handler must not run in the Scheduler Worker.');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertNull($context->getTenant());
        self::assertSame([
            ['validation', RedispatchMessage::class, null],
            ['before', RedispatchMessage::class, null],
            ['validation', ScheduledGlobalMessage::class, null],
            ['before', ScheduledGlobalMessage::class, null],
            ['after', ScheduledGlobalMessage::class, null],
            ['after', RedispatchMessage::class, null],
        ], $middlewareProbe->events);

        $failures = $this->runOne($transport, $bus);
        self::assertCount($failure ? 1 : 0, $failures);
        if ($failure) {
            self::assertStringContainsString('Controlled application Worker failure.', $failures[0]->getMessage());
        }
        self::assertSame([[$label, null]], $probe->handled());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertNull($context->getTenant());
        self::assertSame([
            ['validation', ScheduledGlobalMessage::class, null],
            ['before', ScheduledGlobalMessage::class, null],
            ['after', ScheduledGlobalMessage::class, null],
        ], array_slice($middlewareProbe->events, 6));
    }

    /** @return list<\Throwable> */
    private function runOne(TransportInterface $transport, MessageBusInterface $bus): array
    {
        $failures = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable();
        });
        (new Worker(['proof' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        return $failures;
    }
}
