<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestExceptionTracker;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestTerminationListener;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;

final class TenantRequestTerminationListenerTest extends TestCase
{
    public function testPrimaryHttpFailureIsPreservedWhenTerminalCleanupAlsoFails(): void
    {
        $operationFailure = new \RuntimeException('controller failure');
        $cleanupFailure = new \LogicException('cleanup detail');
        $request = Request::create('/failure');
        $request->attributes->set(TenantRequestExceptionTracker::REQUEST_ATTRIBUTE, $operationFailure);
        $resetter = new class($cleanupFailure) implements TenantStateResetterInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function reset(): void
            {
                throw $this->failure;
            }
        };
        $event = new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            new Response(),
        );

        try {
            (new TenantRequestTerminationListener($resetter))->onKernelTerminate($event);
            self::fail('The terminal cleanup failure must remain observable.');
        } catch (TenantContextTransitionException $exception) {
            self::assertSame($operationFailure, $exception->getPrevious());
            self::assertSame($cleanupFailure, $exception->getCleanupFailure());
            self::assertStringNotContainsString('cleanup detail', $exception->getMessage());
        }
    }
}
