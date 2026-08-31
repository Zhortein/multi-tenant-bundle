<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

final class TenantSendingMiddlewareTest extends TestCase
{
    private TenantContextInterface $context;
    private StackInterface $stack;
    private MiddlewareInterface $next;
    private TenantSendingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->context = $this->createMock(TenantContextInterface::class);
        $this->stack = $this->createMock(StackInterface::class);
        $this->next = $this->createMock(MiddlewareInterface::class);
        $this->middleware = new TenantSendingMiddleware($this->context);
        $this->stack->method('next')->willReturn($this->next);
    }

    public function testTenantAwareMessageGetsCurrentTenantStampBeforeNextMiddleware(): void
    {
        $this->context->method('getTenant')->willReturn($this->tenant('a'));
        $envelope = new Envelope(new TenantMessage());
        $this->next->expects(self::once())->method('handle')->with(
            self::callback(static fn (Envelope $value): bool => 'a' === $value->last(TenantStamp::class)?->getTenantId()),
            $this->stack,
        )->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    public function testIdenticalExistingStampIsKept(): void
    {
        $this->context->method('getTenant')->willReturn($this->tenant('a'));
        $envelope = new Envelope(new TenantMessage(), [new TenantStamp('a')]);
        $this->next->expects(self::once())->method('handle')->with($envelope, $this->stack)->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    public function testTenantAwareMessageWithoutContextIsRejectedBeforeNextMiddleware(): void
    {
        $this->next->expects(self::never())->method('handle');
        $this->expectException(MissingTenantContextException::class);
        $this->middleware->handle(new Envelope(new TenantMessage()), $this->stack);
    }

    public function testContradictoryStampIsRejected(): void
    {
        $this->context->method('getTenant')->willReturn($this->tenant('a'));
        $this->next->expects(self::never())->method('handle');
        $this->expectException(TenantMismatchException::class);
        $this->middleware->handle(new Envelope(new TenantMessage(), [new TenantStamp('b')]), $this->stack);
    }

    public function testExplicitGlobalMessageWithoutStampIsAccepted(): void
    {
        $envelope = new Envelope(new GlobalMessage());
        $this->next->expects(self::once())->method('handle')->with($envelope, $this->stack)->willReturn($envelope);
        self::assertSame($envelope, $this->middleware->handle($envelope, $this->stack));
    }

    #[DataProvider('invalidClassificationProvider')]
    public function testInvalidClassificationIsRejected(object $message, array $stamps, string $exception): void
    {
        $this->next->expects(self::never())->method('handle');
        $this->expectException($exception);
        $this->middleware->handle(new Envelope($message, $stamps), $this->stack);
    }

    public static function invalidClassificationProvider(): iterable
    {
        yield 'unclassified' => [new \stdClass(), [], UnclassifiedMessageException::class];
        yield 'both classifications' => [new DoublyClassifiedMessage(), [], UnclassifiedMessageException::class];
        yield 'global with stamp' => [new GlobalMessage(), [new TenantStamp('a')], TenantMismatchException::class];
    }

    private function tenant(string $id): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}

final class TenantMessage implements TenantAwareMessageInterface
{
}
final class GlobalMessage implements GlobalMessageInterface
{
}
final class DoublyClassifiedMessage implements TenantAwareMessageInterface, GlobalMessageInterface
{
}
