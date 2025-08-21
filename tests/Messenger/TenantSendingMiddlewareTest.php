<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware
 */
class TenantSendingMiddlewareTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantSendingMiddleware $middleware;
    private StackInterface $stack;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->middleware = new TenantSendingMiddleware($this->tenantContext);
        $this->stack = $this->createMock(StackInterface::class);
    }

    public function testHandleWithTenantContextAttachesTenantStamp(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('123');

        $this->tenantContext->method('hasTenant')->willReturn(true);
        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $expectedEnvelope = $envelope->with(new TenantStamp('123'));

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $env) {
                $stamp = $env->last(TenantStamp::class);

                return $stamp instanceof TenantStamp && '123' === $stamp->getTenantId();
            }), $this->stack)
            ->willReturn($expectedEnvelope);

        // Act
        $result = $this->middleware->handle($envelope, $this->stack);

        // Assert
        $this->assertSame($expectedEnvelope, $result);
    }

    public function testHandleWithoutTenantContextDoesNotAttachStamp(): void
    {
        // Arrange
        $this->tenantContext->method('hasTenant')->willReturn(false);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($envelope, $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->middleware->handle($envelope, $this->stack);

        // Assert
        $this->assertSame($envelope, $result);
        $this->assertNull($envelope->last(TenantStamp::class));
    }

    public function testHandleWithExistingTenantStampDoesNotAddAnother(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('123');

        $this->tenantContext->method('hasTenant')->willReturn(true);
        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $existingStamp = new TenantStamp('456');
        $envelope = new Envelope($message, [$existingStamp]);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($envelope, $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->middleware->handle($envelope, $this->stack);

        // Assert
        $this->assertSame($envelope, $result);
        $stamp = $envelope->last(TenantStamp::class);
        $this->assertInstanceOf(TenantStamp::class, $stamp);
        $this->assertSame('456', $stamp->getTenantId()); // Original stamp preserved
    }

    public function testHandleWithNullTenantDoesNotAttachStamp(): void
    {
        // Arrange
        $this->tenantContext->method('hasTenant')->willReturn(true);
        $this->tenantContext->method('getTenant')->willReturn(null);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($envelope, $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->middleware->handle($envelope, $this->stack);

        // Assert
        $this->assertSame($envelope, $result);
        $this->assertNull($envelope->last(TenantStamp::class));
    }

    public function testHandleWithIntegerTenantId(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn(456);

        $this->tenantContext->method('hasTenant')->willReturn(true);
        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $expectedEnvelope = $envelope->with(new TenantStamp('456'));

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $env) {
                $stamp = $env->last(TenantStamp::class);

                return $stamp instanceof TenantStamp && '456' === $stamp->getTenantId();
            }), $this->stack)
            ->willReturn($expectedEnvelope);

        // Act
        $result = $this->middleware->handle($envelope, $this->stack);

        // Assert
        $this->assertSame($expectedEnvelope, $result);
    }
}
