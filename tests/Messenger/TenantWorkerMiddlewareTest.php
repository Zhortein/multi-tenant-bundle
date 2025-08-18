<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware
 */
class TenantWorkerMiddlewareTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantRegistryInterface $tenantRegistry;
    private TenantSessionConfigurator $sessionConfigurator;
    private TenantWorkerMiddleware $middleware;
    private StackInterface $stack;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);

        // Create a real TenantSessionConfigurator with mocked dependencies
        $connection = $this->createMock(Connection::class);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $logger = $this->createMock(LoggerInterface::class);

        $this->sessionConfigurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $connection,
            $this->tenantRegistry,
            false, // RLS disabled for testing
            'app.tenant_id',
            $logger
        );

        $this->middleware = new TenantWorkerMiddleware(
            $this->tenantContext,
            $this->tenantRegistry,
            $this->sessionConfigurator
        );

        $this->stack = $this->createMock(StackInterface::class);
    }

    public function testHandleWithTenantStampRestoresTenantContext(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenantStamp = new TenantStamp('123');

        $message = new \stdClass();
        $envelope = new Envelope($message, [$tenantStamp]);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('123')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext->expects($this->once())
            ->method('clear');

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
    }

    public function testHandleWithoutTenantStampProceedsWithoutContext(): void
    {
        // Arrange
        $message = new \stdClass();
        $envelope = new Envelope($message);

        $this->tenantRegistry->expects($this->never())
            ->method('findById');

        $this->tenantContext->expects($this->never())
            ->method('setTenant');

        $this->tenantContext->expects($this->never())
            ->method('clear');

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
    }

    public function testHandleWithTenantStampButTenantNotFoundProceedsWithoutContext(): void
    {
        // Arrange
        $tenantStamp = new TenantStamp('nonexistent');

        $message = new \stdClass();
        $envelope = new Envelope($message, [$tenantStamp]);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('nonexistent')
            ->willReturn(null);

        $this->tenantContext->expects($this->never())
            ->method('setTenant');

        $this->tenantContext->expects($this->never())
            ->method('clear');

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
    }

    public function testHandleAlwaysClearsTenantContextEvenOnException(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenantStamp = new TenantStamp('123');

        $message = new \stdClass();
        $envelope = new Envelope($message, [$tenantStamp]);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('123')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $exception = new \RuntimeException('Test exception');

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($envelope, $this->stack)
            ->willThrowException($exception);

        // Expect clear to be called even when exception is thrown
        $this->tenantContext->expects($this->once())
            ->method('clear');

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $this->middleware->handle($envelope, $this->stack);
    }

    public function testHandleWithIntegerTenantId(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenantStamp = new TenantStamp('456');

        $message = new \stdClass();
        $envelope = new Envelope($message, [$tenantStamp]);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('456')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext->expects($this->once())
            ->method('clear');

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
    }

    public function testHandleWithMultipleTenantStampsUsesLast(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $firstStamp = new TenantStamp('123');
        $lastStamp = new TenantStamp('456');

        $message = new \stdClass();
        $envelope = new Envelope($message, [$firstStamp, $lastStamp]);

        // Should use the last stamp (456)
        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('456')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext->expects($this->once())
            ->method('clear');

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
    }
}
