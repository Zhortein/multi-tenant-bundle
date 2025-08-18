<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;

/**
 * @covers \Zhortein\MultiTenantBundle\EventListener\TenantResolutionExceptionListener
 */
final class TenantResolutionExceptionListenerTest extends TestCase
{
    private LoggerInterface $logger;
    private HttpKernelInterface $kernel;
    private Request $request;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->request = new Request([], [], [], [], [], ['REQUEST_URI' => '/test', 'REQUEST_METHOD' => 'GET']);
    }

    public function testIgnoresNonTenantResolutionExceptions(): void
    {
        $listener = new TenantResolutionExceptionListener('prod', $this->logger);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Other error'));

        $listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testHandlesTenantResolutionExceptionInProduction(): void
    {
        $listener = new TenantResolutionExceptionListener('prod', $this->logger);
        $diagnostics = ['resolvers_tried' => ['path', 'subdomain']];
        $exception = new TenantResolutionException('Resolution failed', $diagnostics);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Unable to resolve tenant from request', $data['error']);
        $this->assertSame(400, $data['code']);
        $this->assertArrayNotHasKey('diagnostics', $data);
        $this->assertArrayNotHasKey('exception_message', $data);
    }

    public function testHandlesTenantResolutionExceptionInDevelopment(): void
    {
        $listener = new TenantResolutionExceptionListener('dev', $this->logger);
        $diagnostics = ['resolvers_tried' => ['path', 'subdomain']];
        $exception = new TenantResolutionException('Resolution failed', $diagnostics);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Unable to resolve tenant from request', $data['error']);
        $this->assertSame(400, $data['code']);
        $this->assertSame($diagnostics, $data['diagnostics']);
        $this->assertSame('Resolution failed', $data['exception_message']);
        $this->assertSame('resolution_failed', $data['type']);
    }

    public function testHandlesAmbiguousTenantResolutionExceptionInProduction(): void
    {
        $listener = new TenantResolutionExceptionListener('prod', $this->logger);
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        $conflictingResults = ['subdomain' => $tenant1, 'path' => $tenant2];
        $exception = new AmbiguousTenantResolutionException($conflictingResults);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Multiple tenant resolution strategies returned different results', $data['error']);
        $this->assertSame(400, $data['code']);
        $this->assertArrayNotHasKey('diagnostics', $data);
    }

    public function testHandlesAmbiguousTenantResolutionExceptionInDevelopment(): void
    {
        $listener = new TenantResolutionExceptionListener('dev', $this->logger);
        $tenant1 = $this->createMockTenant('tenant1');
        $tenant2 = $this->createMockTenant('tenant2');
        $conflictingResults = ['subdomain' => $tenant1, 'path' => $tenant2];
        $diagnostics = ['resolvers_tried' => ['subdomain', 'path']];
        $exception = new AmbiguousTenantResolutionException($conflictingResults, $diagnostics);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Multiple tenant resolution strategies returned different results', $data['error']);
        $this->assertSame($diagnostics, $data['diagnostics']);
        $this->assertSame('ambiguous_resolution', $data['type']);
    }

    public function testLogsExceptionDetails(): void
    {
        $listener = new TenantResolutionExceptionListener('prod', $this->logger);
        $diagnostics = ['resolvers_tried' => ['path']];
        $exception = new TenantResolutionException('Resolution failed', $diagnostics);
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // Expect the logger to be called
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Tenant resolution failed', $this->callback(function ($context) use ($diagnostics) {
                return 'Resolution failed' === $context['exception']
                    && $context['diagnostics'] === $diagnostics
                    && '/test' === $context['request_uri']
                    && 'GET' === $context['request_method'];
            }));

        $listener->onKernelException($event);
    }

    public function testWorksInTestEnvironment(): void
    {
        $listener = new TenantResolutionExceptionListener('test', $this->logger);
        $exception = new TenantResolutionException('Resolution failed');
        $event = new ExceptionEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $data = json_decode($response->getContent(), true);

        // Test environment should include diagnostics like dev
        $this->assertArrayHasKey('diagnostics', $data);
        $this->assertArrayHasKey('exception_message', $data);
    }

    private function createMockTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }
}
