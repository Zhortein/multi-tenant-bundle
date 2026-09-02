<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\EventListener\TenantRequestListener;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

final class TenantRequestListenerTest extends TestCase
{
    public function testNullResolutionCannotRetainPreviousTenant(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->tenant('tenant-a'));
        $listener = new TenantRequestListener($context, $this->resolver(null));

        $listener->onKernelRequest($this->event());

        self::assertNull($context->getTenant());
    }

    public function testResolverExceptionCannotRetainPreviousTenant(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->tenant('tenant-a'));
        $listener = new TenantRequestListener($context, $this->resolver(new \RuntimeException('resolution failed')));

        try {
            $listener->onKernelRequest($this->event());
            self::fail('Resolution failures must remain observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('resolution failed', $exception->getMessage());
        }

        self::assertNull($context->getTenant());
    }

    private function event(): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/unresolved'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function resolver(TenantInterface|\Throwable|null $result): TenantResolverInterface
    {
        return new class($result) implements TenantResolverInterface {
            public function __construct(private readonly TenantInterface|\Throwable|null $result)
            {
            }

            public function resolveTenant(Request $request): ?TenantInterface
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private function tenant(string $id): TenantInterface
    {
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}
