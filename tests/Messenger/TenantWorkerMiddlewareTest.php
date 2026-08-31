<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

final class TenantWorkerMiddlewareTest extends TestCase
{
    private TenantContextInterface $context;
    private TenantRegistryInterface $registry;
    private StackInterface $stack;
    private MiddlewareInterface $next;
    private TenantWorkerMiddleware $middleware;

    protected function setUp(): void
    {
        $this->context = $this->createMock(TenantContextInterface::class);
        $this->registry = $this->createMock(TenantRegistryInterface::class);
        $this->stack = $this->createMock(StackInterface::class);
        $this->next = $this->createMock(MiddlewareInterface::class);
        $this->stack->method('next')->willReturn($this->next);
        $this->middleware = new TenantWorkerMiddleware($this->context, $this->registry);
    }

    public function testOutgoingDispatchIsIgnoredByWorkerMiddleware(): void
    {
        $envelope = new Envelope(new \stdClass());
        $this->context->expects(self::never())->method('clear');
        $this->next->expects(self::once())->method('handle')->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    public function testTenantContextIsInstalledBeforeHandlerAndClearedAfterSuccess(): void
    {
        $tenant = $this->tenant('a');
        $envelope = $this->received(new WorkerTenantMessage(), new TenantStamp('a'));
        $this->registry->method('findById')->with('a')->willReturn($tenant);
        $this->context->expects(self::once())->method('setTenant')->with($tenant);
        $this->context->expects(self::exactly(2))->method('clear');
        $this->next->expects(self::once())->method('handle')->with($envelope, $this->stack)->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    public function testContextIsClearedAfterHandlerException(): void
    {
        $this->registry->method('findById')->willReturn($this->tenant('a'));
        $this->context->expects(self::exactly(2))->method('clear');
        $this->next->method('handle')->willThrowException(new \RuntimeException('handler failed'));
        $this->expectExceptionMessage('handler failed');
        $this->middleware->handle($this->received(new WorkerTenantMessage(), new TenantStamp('a')), $this->stack);
    }

    #[DataProvider('rejectedEnvelopeProvider')]
    public function testInvalidReceivedMessageIsRejectedBeforeHandler(Envelope $envelope, string $exception): void
    {
        $this->context->expects(self::exactly(2))->method('clear');
        $this->next->expects(self::never())->method('handle');
        $this->expectException($exception);
        $this->middleware->handle($envelope, $this->stack);
    }

    public static function rejectedEnvelopeProvider(): iterable
    {
        yield 'tenant message without stamp' => [self::receivedEnvelope(new WorkerTenantMessage()), MissingTenantStampException::class];
        yield 'unclassified message' => [self::receivedEnvelope(new \stdClass()), UnclassifiedMessageException::class];
        yield 'global message with stamp' => [self::receivedEnvelope(new WorkerGlobalMessage(), new TenantStamp('a')), TenantMismatchException::class];
        yield 'contradictory stamps' => [self::receivedEnvelope(new WorkerTenantMessage(), new TenantStamp('a'), new TenantStamp('b')), TenantMismatchException::class];
    }

    public function testUnknownTenantIsRejectedBeforeHandler(): void
    {
        $this->registry->method('findById')->with('missing')->willReturn(null);
        $this->context->expects(self::exactly(2))->method('clear');
        $this->next->expects(self::never())->method('handle');
        $this->expectException(UnknownTenantException::class);
        $this->middleware->handle($this->received(new WorkerTenantMessage(), new TenantStamp('missing')), $this->stack);
    }

    public function testExplicitGlobalMessageRunsWithoutTenantAndIsCleaned(): void
    {
        $envelope = $this->received(new WorkerGlobalMessage());
        $this->registry->expects(self::never())->method('findById');
        $this->context->expects(self::exactly(2))->method('clear');
        $this->next->expects(self::once())->method('handle')->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    public function testPreExistingContextIsRestoredAfterReceivedMessage(): void
    {
        $previous = $this->tenant('previous');
        $messageTenant = $this->tenant('message');
        $envelope = $this->received(new WorkerTenantMessage(), new TenantStamp('message'));
        $this->context->method('getTenant')->willReturn($previous);
        $this->registry->expects(self::once())->method('findById')->with('message')->willReturn($messageTenant);
        $this->stack->expects(self::once())->method('next')->willReturn($this->next);
        $this->next->expects(self::once())->method('handle')->willReturn($envelope);
        $this->context->expects(self::exactly(2))->method('setTenant')->willReturnCallback(
            static function (TenantInterface $tenant) use ($messageTenant, $previous): void {
                self::assertTrue(in_array($tenant, [$messageTenant, $previous], true));
            },
        );
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    private function received(object $message, TenantStamp ...$stamps): Envelope
    {
        return self::receivedEnvelope($message, ...$stamps);
    }

    private static function receivedEnvelope(object $message, TenantStamp ...$stamps): Envelope
    {
        return new Envelope($message, [...$stamps, new ReceivedStamp('async')]);
    }

    private function tenant(string $id): TenantInterface
    {
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}

final class WorkerTenantMessage implements TenantAwareMessageInterface
{
}
final class WorkerGlobalMessage implements GlobalMessageInterface
{
}
