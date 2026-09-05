<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use App\Message\ScheduledGlobalMessage;
use App\Scheduler\SchedulerProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

final class Rc10GlobalDispatchReproductionTest extends KernelTestCase
{
    public static function paths(): iterable
    {
        yield 'direct application message' => [false];
        yield 'outgoing RedispatchMessage' => [true];
    }

    #[DataProvider('paths')]
    public function testPublicRc10AddsContradictoryStampAndWorkerRejectsBeforeHandler(bool $wrapped): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $context = $container->get(TenantContextInterface::class);
        $context->setTenant(new Tenant('1', 'tenant-a'));
        $bus = $container->get('messenger.bus.default');
        $message = new ScheduledGlobalMessage('rc10-global-defect');
        self::assertInstanceOf(GlobalMessageInterface::class, $message);
        self::assertNotInstanceOf(TenantAwareMessageInterface::class, $message);
        $envelope = $wrapped
            ? $bus->dispatch(new RedispatchMessage($message, 'scheduler_persistent'))
            : $bus->dispatch($message, [new TransportNamesStamp(['scheduler_persistent'])]);
        self::assertSame('1', $envelope->last(TenantStamp::class)?->getTenantId());
        $connection = $container->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $transport = $container->get('messenger.transport.scheduler_persistent');
        $probe = $container->get(SchedulerProbe::class);
        self::assertSame([], $probe->handled());
        $failures = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable();
        });
        (new Worker(['proof' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
        self::assertCount(1, $failures);
        self::assertInstanceOf(TenantMismatchException::class, $failures[0]);
        self::assertSame([], $probe->handled());
        self::assertNull($context->getTenant());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        fwrite(STDOUT, "RC10 reproduced: global-only payload; tenant 1 stamp added at send; persisted row; Worker TenantMismatchException; zero handlers; final context NONE.\n");
    }
}
