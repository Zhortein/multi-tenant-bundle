<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Unit tests for the TenantSessionConfigurator.
 *
 * @covers \Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator
 */
final class TenantSessionConfiguratorTest extends TestCase
{
    private TenantContextInterface&MockObject $tenantContext;
    private Connection&MockObject $connection;
    private TenantRegistryInterface&MockObject $tenantRegistry;
    private LoggerInterface&MockObject $logger;
    private TenantSessionConfigurator $configurator;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->configurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $this->tenantRegistry,
            true, // RLS enabled
            'app.tenant_id',
            $this->logger
        );
    }

    public function testOnKernelRequestSkipsWhenRlsDisabled(): void
    {
        $configurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $this->tenantRegistry,
            false, // RLS disabled
            'app.tenant_id',
            $this->logger
        );

        $event = $this->createRequestEvent(true);

        $this->connection->expects($this->never())->method('executeStatement');

        $configurator->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsSubRequests(): void
    {
        $event = $this->createRequestEvent(false);

        $this->connection->expects($this->never())->method('executeStatement');

        $this->configurator->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsWhenNoTenant(): void
    {
        $event = $this->createRequestEvent(true);

        $this->tenantContext
            ->method('getTenant')
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('No tenant context available for RLS configuration');

        $this->connection->expects($this->never())->method('executeStatement');

        $this->configurator->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsWhenNotPostgreSQL(): void
    {
        $event = $this->createRequestEvent(true);
        $tenant = $this->createTenant(1, 'tenant1');

        $this->tenantContext
            ->method('getTenant')
            ->willReturn($tenant);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class);
        $platform->method('getName')->willReturn('mysql');

        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('RLS is only supported with PostgreSQL, skipping session configuration');

        $this->connection->expects($this->never())->method('executeStatement');

        $this->configurator->onKernelRequest($event);
    }

    public function testOnKernelRequestConfiguresSessionVariable(): void
    {
        $event = $this->createRequestEvent(true);
        $tenant = $this->createTenant(123, 'tenant1');

        $this->tenantContext
            ->method('getTenant')
            ->willReturn($tenant);

        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                'SELECT set_config(?, ?, true)',
                ['app.tenant_id', '123']
            );

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'Configured PostgreSQL session variable for RLS',
                [
                    'tenant_id' => '123',
                    'tenant_slug' => 'tenant1',
                    'session_variable' => 'app.tenant_id',
                ]
            );

        $this->configurator->onKernelRequest($event);
    }

    public function testOnKernelRequestHandlesDatabaseException(): void
    {
        $event = $this->createRequestEvent(true);
        $tenant = $this->createTenant(123, 'tenant1');

        $this->tenantContext
            ->method('getTenant')
            ->willReturn($tenant);

        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $exception = new Exception('Database error');
        $this->connection
            ->method('executeStatement')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Failed to configure PostgreSQL session variable for RLS',
                [
                    'exception' => 'Database error',
                    'tenant_id' => 123,
                    'session_variable' => 'app.tenant_id',
                ]
            );

        $this->configurator->onKernelRequest($event);
    }

    public function testMessengerMiddlewareSkipsWhenRlsDisabled(): void
    {
        $configurator = new TenantSessionConfigurator(
            $this->tenantContext,
            $this->connection,
            $this->tenantRegistry,
            false, // RLS disabled
            'app.tenant_id',
            $this->logger
        );

        $envelope = new Envelope(new \stdClass());
        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())->method('handle')->willReturn($envelope);

        $result = $configurator->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testMessengerMiddlewareConfiguresSessionFromStamp(): void
    {
        $tenant = $this->createTenant(456, 'tenant2');
        $tenantStamp = new TenantStamp('tenant2', 'Tenant 2');
        $envelope = new Envelope(new \stdClass(), [$tenantStamp]);

        $this->tenantRegistry
            ->method('findBySlug')
            ->with('tenant2')
            ->willReturn($tenant);

        $this->tenantContext
            ->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext
            ->method('getTenant')
            ->willReturn($tenant);

        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $expectedCalls = [
            ['SELECT set_config(?, ?, true)', ['app.tenant_id', '456']],
            ['SELECT set_config(?, NULL, true)', ['app.tenant_id']],
        ];
        $callCount = 0;

        $this->connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params = []) use (&$expectedCalls, &$callCount) {
                $this->assertSame($expectedCalls[$callCount][0], $sql);
                $this->assertSame($expectedCalls[$callCount][1], $params);
                ++$callCount;

                return null;
            });

        $this->tenantContext
            ->expects($this->once())
            ->method('clear');

        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())->method('handle')->willReturn($envelope);

        $result = $this->configurator->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testMessengerMiddlewareSkipsWhenNoTenantStamp(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->tenantRegistry->expects($this->never())->method('findBySlug');
        $this->tenantContext->expects($this->never())->method('setTenant');
        $this->connection->expects($this->never())->method('executeStatement');

        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())->method('handle')->willReturn($envelope);

        $result = $this->configurator->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testMessengerMiddlewareSkipsWhenTenantNotFound(): void
    {
        $tenantStamp = new TenantStamp('nonexistent', 'Nonexistent');
        $envelope = new Envelope(new \stdClass(), [$tenantStamp]);

        $this->tenantRegistry
            ->method('findBySlug')
            ->with('nonexistent')
            ->willReturn(null);

        $this->tenantContext->expects($this->never())->method('setTenant');
        $this->connection->expects($this->never())->method('executeStatement');

        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())->method('handle')->willReturn($envelope);

        $result = $this->configurator->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testMessengerMiddlewareClearsContextOnException(): void
    {
        $tenant = $this->createTenant(456, 'tenant2');
        $tenantStamp = new TenantStamp('tenant2', 'Tenant 2');
        $envelope = new Envelope(new \stdClass(), [$tenantStamp]);

        $this->tenantRegistry
            ->method('findBySlug')
            ->with('tenant2')
            ->willReturn($tenant);

        $this->tenantContext
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext
            ->method('getTenant')
            ->willReturn($tenant);

        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->connection
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(
                null, // First call succeeds (set config)
                null  // Second call succeeds (clear config)
            );

        $this->tenantContext
            ->expects($this->once())
            ->method('clear');

        $stack = $this->createMock(StackInterface::class);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())->method('handle')->willThrowException(new \RuntimeException('Handler error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler error');

        $this->configurator->handle($envelope, $stack);
    }

    private function createRequestEvent(bool $isMainRequest): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $requestType = $isMainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST;

        return new RequestEvent($kernel, $request, $requestType);
    }

    private function createTenant(int $id, string $slug): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }
}
