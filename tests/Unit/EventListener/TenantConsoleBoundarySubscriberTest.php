<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantConsoleBoundarySubscriber;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;

final class TenantConsoleBoundarySubscriberTest extends TestCase
{
    public function testCommandFailureRemainsPrimaryWhenErrorCleanupAlsoFails(): void
    {
        $operationFailure = new \RuntimeException('command detail');
        $cleanupFailure = new \LogicException('cleanup detail');
        $event = new ConsoleErrorEvent(
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
            $operationFailure,
        );
        $resetter = new class($cleanupFailure) implements TenantStateResetterInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function reset(): void
            {
                throw $this->failure;
            }
        };

        $subscriber = new TenantConsoleBoundarySubscriber($resetter);
        $subscriber->onError($event);

        $combined = $event->getError();
        self::assertInstanceOf(TenantContextTransitionException::class, $combined);
        self::assertSame($operationFailure, $combined->getPrevious());
        self::assertSame($cleanupFailure, $combined->getCleanupFailure());
        self::assertStringNotContainsString('cleanup detail', $combined->getMessage());

        // Console dispatches TERMINATE after ERROR. A second cleanup attempt
        // must not replace the combined primary/cleanup failure.
        $subscriber->onTerminate(new ConsoleTerminateEvent(
            $this->createStub(Command::class),
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
            1,
        ));
        self::assertSame($combined, $event->getError());
    }
}
